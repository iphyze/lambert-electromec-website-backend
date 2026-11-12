<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/analyticsService.php';

// Public, cookie-free statistics beacon used by the Lambert website.
// Always answers 204 (or 422 for a malformed payload) and never echoes data.
requireMethod('POST');
header('Cache-Control: no-store');

$respond = static function (int $status = 204): void {
    http_response_code($status);
    exit;
};

if (!analyticsEnabled()) {
    $respond();
}

$raw = file_get_contents('php://input', false, null, 0, 4097);
if ($raw === false || $raw === '' || strlen($raw) > 4096) {
    $respond(422);
}
try {
    $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    $respond(422);
}
if (!is_array($payload)) {
    $respond(422);
}

// Only the Lambert website itself may send statistics.
$originHost = analyticsCleanHost((string) parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST));
if ($originHost === '') {
    $originHost = analyticsCleanHost((string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
}
if ($originHost === '' || !in_array($originHost, analyticsAllowedHosts(), true)) {
    $respond();
}

if (analyticsRespectDoNotTrack() && analyticsDoNotTrackRequested()) {
    $respond();
}
$userAgent = requestUserAgent();
if (analyticsIsBot($userAgent)) {
    $respond();
}

$type = (string) ($payload['type'] ?? '');
$pageViewId = analyticsCleanPageViewId($payload['page_view_id'] ?? '');
if (!in_array($type, ['pageview', 'engagement', 'event'], true) || ($type !== 'event' && $pageViewId === null)) {
    $respond(422);
}

try {
    $visitorHash = analyticsVisitorHash($conn, $originHost);

    if ($type === 'engagement') {
        analyticsRecordEngagement(
            $conn,
            $visitorHash,
            (string) $pageViewId,
            (int) ($payload['engaged_ms'] ?? 0),
            (int) ($payload['max_scroll_pct'] ?? 0)
        );
        $respond();
    }

    if (analyticsRateLimited($conn, $visitorHash)) {
        $respond();
    }

    if ($type === 'pageview') {
        $client = analyticsParseUserAgent($userAgent) + ['country' => analyticsCountry()];
        analyticsRecordPageview($conn, $visitorHash, (string) $pageViewId, [
            'path' => (string) ($payload['path'] ?? ''),
            'locale' => (string) ($payload['locale'] ?? 'en'),
            'referrer' => (string) ($payload['referrer'] ?? ''),
            'utm' => $payload['utm'] ?? [],
        ], $client);
    } else {
        $name = (string) ($payload['name'] ?? '');
        if (!in_array($name, analyticsEventNames(), true)) {
            $respond(422);
        }
        analyticsRecordEvent($conn, $visitorHash, $pageViewId, $name, $payload['label'] ?? null);
    }

    // Occasionally finalise yesterday's totals and purge expired raw rows.
    if (random_int(1, 100) === 1) {
        analyticsMaintenance($conn);
    }
} catch (Throwable $error) {
    error_log('Lambert analytics collect error: ' . $error->getMessage());
}

$respond();
