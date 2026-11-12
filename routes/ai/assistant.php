<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/aiAssistantService.php';
require_once __DIR__ . '/../../includes/aiRequestService.php';

requireMethod('POST');
requireTrustedRequestOrigin();
applyNoStoreHeaders();

if (!aiAssistantAvailable()) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'The Lambert assistant is temporarily unavailable.',
    ], 503);
}

$body = requestJsonBody(aiAssistantRequestMaxBytes());

try {
    $message = validatedAiAssistantMessage($body['message'] ?? null);
    $locale = validatedAiAssistantLocale($body['locale'] ?? 'en');
    $history = validatedAiAssistantHistory($body['history'] ?? []);
} catch (InvalidArgumentException $error) {
    jsonResponse([
        'status' => 'Failed',
        'message' => $error->getMessage(),
    ], 422);
}

$clientHash = aiRequestClientHash();
assertAiAssistantRateLimit($conn, $clientHash, $locale, mb_strlen($message));
$eventId = startAiRequestEvent($conn, 'website_assistant', $clientHash, $locale, mb_strlen($message));
$startedAt = microtime(true);

try {
    $result = createLambertWebsiteAssistantResponse(
        $conn,
        $message,
        $locale,
        $history,
        'web_' . substr($clientHash, 0, 32)
    );

    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    completeAiRequestEvent($conn, $eventId, (array) $result['provider'], $latencyMs);

    jsonResponse([
        'success' => true,
        'status' => 'Success',
        'data' => $result['assistant'],
    ]);
} catch (OpenAiServiceException $error) {
    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    failAiRequestEvent($conn, $eventId, $error->providerErrorCode ?? 'provider_error', $latencyMs);

    $status = (int) $error->getCode();
    if ($status < 500 || $status > 504) {
        $status = 503;
    }

    jsonResponse([
        'status' => 'Failed',
        'message' => 'The Lambert assistant is temporarily unavailable. Please try again later.',
    ], $status);
} catch (Throwable $error) {
    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
    failAiRequestEvent($conn, $eventId, 'internal_error', $latencyMs);
    throw $error;
}
