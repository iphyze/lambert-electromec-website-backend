<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/contactService.php';
require_once __DIR__ . '/../../../includes/contactAiIntelligenceService.php';
require_once __DIR__ . '/../../../includes/aiRequestService.php';

requireMethod('GET', 'POST');
$admin = requireAdministrator($conn);
applyNoStoreHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $submissionId = positiveInt($_GET['id'] ?? null, 'Contact submission ID');
    ensureContactSubmissionExists($conn, $submissionId);
    $history = contactAiAnalysisHistory($conn, $submissionId, 10);

    jsonResponse([
        'status' => 'Success',
        'data' => [
            'latest' => $history[0] ?? null,
            'history' => $history,
        ],
    ]);
}

requireCsrfToken();
if (!aiEnquiryIntelligenceAvailable()) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Enquiry Intelligence is temporarily unavailable.',
    ], 503);
}

$body = requestJsonBody(8192);
$submissionId = positiveInt($body['id'] ?? null, 'Contact submission ID');
$action = strtolower(contentString($body['action'] ?? 'analyse', 30, true, 'Action'));
if (!in_array($action, ['analyse', 'regenerate'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Unsupported Enquiry Intelligence action.'], 422);
}

$submission = ensureContactSubmissionExists($conn, $submissionId);
$locale = strtolower((string) ($submission['locale'] ?? 'en')) === 'fr' ? 'fr' : 'en';
$requestChars = mb_strlen((string) ($submission['message'] ?? ''));
$clientHash = aiAdminRequestClientHash((int) $admin['id']);
assertAiEnquiryRateLimit($conn, $clientHash, $locale, $requestChars);
$eventId = startAiRequestEvent($conn, 'enquiry_intelligence', $clientHash, $locale, $requestChars);
$startedAt = microtime(true);

try {
    $analysis = contactAiGenerateAndStore($conn, $submissionId, (int) $admin['id']);
    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    $providerPayload = [
        'provider' => (string) ($analysis['provider'] ?? 'local'),
        'model' => (string) ($analysis['model'] ?? 'lambert-enquiry-fallback-v1'),
        'response_id' => $analysis['provider_response_id'] ?? null,
        'fallback_reason' => $analysis['fallback_reason'] ?? null,
        'usage' => [
            'input_tokens' => $analysis['usage']['input_tokens'] ?? null,
            'output_tokens' => $analysis['usage']['output_tokens'] ?? null,
            'total_tokens' => $analysis['usage']['total_tokens'] ?? null,
        ],
    ];
    completeAiRequestEvent($conn, $eventId, $providerPayload, $latencyMs);

    recordAuditLog(
        $conn,
        (int) $admin['id'],
        $action === 'regenerate' ? 'contact.ai_analysis.regenerated' : 'contact.ai_analysis.generated',
        'contact',
        $submissionId,
        [
            'analysis_id' => (int) $analysis['id'],
            'generation_no' => (int) $analysis['generation_no'],
            'provider' => (string) ($analysis['provider'] ?? ''),
            'classification' => (string) ($analysis['classification'] ?? ''),
            'priority' => (string) ($analysis['priority'] ?? ''),
        ]
    );

    jsonResponse([
        'status' => 'Success',
        'message' => $action === 'regenerate'
            ? 'Enquiry Intelligence analysis regenerated.'
            : 'Enquiry Intelligence analysis completed.',
        'data' => $analysis,
    ]);
} catch (OpenAiServiceException $error) {
    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    failAiRequestEvent($conn, $eventId, $error->providerErrorCode ?? 'provider_error', $latencyMs);
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Enquiry Intelligence is temporarily unavailable. Please try again later.',
    ], 503);
} catch (Throwable $error) {
    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    failAiRequestEvent($conn, $eventId, 'internal_error', $latencyMs);
    throw $error;
}
