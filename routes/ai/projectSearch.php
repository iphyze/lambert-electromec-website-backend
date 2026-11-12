<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/aiProjectSearchService.php';
require_once __DIR__ . '/../../includes/aiRequestService.php';

requireMethod('POST');
requireTrustedRequestOrigin();
applyNoStoreHeaders();

if (!aiProjectSearchAvailable()) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'AI Project Search is temporarily unavailable.',
    ], 503);
}

$body = requestJsonBody(aiProjectSearchRequestMaxBytes());

try {
    $query = validatedAiProjectSearchQuery($body['query'] ?? null);
    $locale = validatedAiProjectSearchLocale($body['locale'] ?? 'en');
} catch (InvalidArgumentException $error) {
    jsonResponse([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ], 422);
}

$clientHash = aiRequestClientHash();
assertAiProjectSearchRateLimit($conn, $clientHash, $locale, mb_strlen($query));
$eventId = startAiRequestEvent($conn, 'project_search', $clientHash, $locale, mb_strlen($query));
$startedAt = microtime(true);

try {
    $result = createLambertAiProjectSearch(
        $conn,
        $query,
        $locale,
        'project_search_' . substr($clientHash, 0, 32)
    );

    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    completeAiRequestEvent($conn, $eventId, (array) $result['provider'], $latencyMs);

    jsonResponse([
        'success' => true,
        'status' => 'Success',
        'data' => $result['search'],
    ]);
} catch (OpenAiServiceException $error) {
    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    failAiRequestEvent($conn, $eventId, $error->providerErrorCode ?? 'provider_error', $latencyMs);

    jsonResponse([
        'status' => 'Failed',
        'message' => 'AI Project Search is temporarily unavailable. Please try again later.',
    ], 503);
} catch (Throwable $error) {
    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    failAiRequestEvent($conn, $eventId, 'internal_error', $latencyMs);
    throw $error;
}
