<?php

declare(strict_types=1);

require_once __DIR__ . '/aiConfig.php';
require_once __DIR__ . '/contentService.php';

function aiRequestClientHash(): string
{
    return hash_hmac('sha256', clientIpAddress(), applicationHashSecret('AI_RATE_LIMIT_SECRET'));
}

function aiAdminRequestClientHash(int $adminId): string
{
    return hash_hmac('sha256', 'admin:' . $adminId, applicationHashSecret('AI_RATE_LIMIT_SECRET'));
}

function aiRequestCountSince(mysqli $conn, string $feature, string $clientHash, string $cutoff): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM ai_request_events
         WHERE feature = ?
           AND client_hash = ?
           AND status IN ('started','completed','failed')
           AND created_at >= ?"
    );
    $stmt->bind_param('sss', $feature, $clientHash, $cutoff);
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $total;
}

function aiRecordBlockedRequest(mysqli $conn, string $feature, string $clientHash, string $locale, int $requestChars): void
{
    $stmt = $conn->prepare(
        "INSERT INTO ai_request_events
            (feature, client_hash, locale, status, request_chars, completed_at)
         VALUES (?, ?, ?, 'blocked', ?, NOW())"
    );
    $stmt->bind_param('sssi', $feature, $clientHash, $locale, $requestChars);
    $stmt->execute();
    $stmt->close();
}

function assertAiAssistantRateLimit(mysqli $conn, string $clientHash, string $locale, int $requestChars): void
{
    $now = new DateTimeImmutable('now', appTimeZone());
    $minuteCutoff = $now->modify('-1 minute')->format('Y-m-d H:i:s');
    $hourCutoff = $now->modify('-1 hour')->format('Y-m-d H:i:s');

    $minuteCount = aiRequestCountSince($conn, 'website_assistant', $clientHash, $minuteCutoff);
    $hourCount = aiRequestCountSince($conn, 'website_assistant', $clientHash, $hourCutoff);

    if ($minuteCount >= aiAssistantRateLimitPerMinute() || $hourCount >= aiAssistantRateLimitPerHour()) {
        aiRecordBlockedRequest($conn, 'website_assistant', $clientHash, $locale, $requestChars);
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Too many assistant requests were made recently. Please try again shortly.',
        ], 429);
    }
}

function startAiRequestEvent(
    mysqli $conn,
    string $feature,
    string $clientHash,
    string $locale,
    int $requestChars
): int {
    $stmt = $conn->prepare(
        "INSERT INTO ai_request_events
            (feature, client_hash, locale, status, provider, model, request_chars)
         VALUES (?, ?, ?, 'started', ?, ?, ?)"
    );
    if (aiEnabled()) {
        $provider = mb_substr(strtolower(trim((string) envValue('AI_PROVIDER', 'openai'))), 0, 40);
        $model = mb_substr(trim((string) envValue('OPENAI_MODEL', 'gpt-5.6-luna')), 0, 100);
        $provider = $provider !== '' ? $provider : 'openai';
        $model = $model !== '' ? $model : 'gpt-5.6-luna';
    } else {
        $provider = 'local';
        $model = 'lambert-knowledge-fallback-v1';
    }
    $stmt->bind_param('sssssi', $feature, $clientHash, $locale, $provider, $model, $requestChars);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    if (random_int(1, 100) === 1) {
        $days = aiRequestEventRetentionDays();
        $conn->query("DELETE FROM ai_request_events WHERE created_at < (NOW() - INTERVAL {$days} DAY)");
    }

    return $id;
}

function completeAiRequestEvent(mysqli $conn, int $eventId, array $providerResponse, int $latencyMs): void
{
    $usage = is_array($providerResponse['usage'] ?? null) ? $providerResponse['usage'] : [];
    $inputTokens = isset($usage['input_tokens']) ? max(0, (int) $usage['input_tokens']) : null;
    $outputTokens = isset($usage['output_tokens']) ? max(0, (int) $usage['output_tokens']) : null;
    $totalTokens = isset($usage['total_tokens']) ? max(0, (int) $usage['total_tokens']) : null;
    $provider = mb_substr((string) ($providerResponse['provider'] ?? aiProvider()), 0, 40);
    $model = mb_substr((string) ($providerResponse['model'] ?? openAiModel()), 0, 100);
    $responseId = mb_substr((string) ($providerResponse['response_id'] ?? ''), 0, 160);
    $responseId = $responseId !== '' ? $responseId : null;
    $fallbackReason = trim((string) ($providerResponse['fallback_reason'] ?? ''));
    // Scope-guard reasons are recorded in the existing error_code field without a migration.
    $errorCode = in_array($provider, ['local', 'scope_guard'], true) && $fallbackReason !== ''
        ? mb_substr($fallbackReason, 0, 100)
        : null;
    $latencyMs = max(0, $latencyMs);

    $stmt = $conn->prepare(
        "UPDATE ai_request_events
         SET status = 'completed', provider = ?, model = ?, provider_response_id = ?,
             input_tokens = ?, output_tokens = ?, total_tokens = ?, latency_ms = ?,
             error_code = ?, completed_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param(
        'sssiiiisi',
        $provider,
        $model,
        $responseId,
        $inputTokens,
        $outputTokens,
        $totalTokens,
        $latencyMs,
        $errorCode,
        $eventId
    );
    $stmt->execute();
    $stmt->close();
}

function failAiRequestEvent(mysqli $conn, int $eventId, ?string $errorCode, int $latencyMs): void
{
    $safeCode = trim((string) $errorCode);
    $safeCode = $safeCode !== '' ? mb_substr($safeCode, 0, 100) : null;
    $latencyMs = max(0, $latencyMs);

    $stmt = $conn->prepare(
        "UPDATE ai_request_events
         SET status = 'failed', error_code = ?, latency_ms = ?, completed_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('sii', $safeCode, $latencyMs, $eventId);
    $stmt->execute();
    $stmt->close();
}

function assertAiEnquiryRateLimit(
    mysqli $conn,
    string $clientHash,
    string $locale,
    int $requestChars
): void {
    $now = new DateTimeImmutable('now', appTimeZone());
    $hourCutoff = $now->modify('-1 hour')->format('Y-m-d H:i:s');
    $hourCount = aiRequestCountSince($conn, 'enquiry_intelligence', $clientHash, $hourCutoff);

    if ($hourCount >= aiEnquiryRateLimitPerHour()) {
        aiRecordBlockedRequest($conn, 'enquiry_intelligence', $clientHash, $locale, $requestChars);
        jsonResponse([
            'status' => 'Failed',
            'message' => 'The Enquiry Intelligence generation limit has been reached. Please try again later.',
        ], 429);
    }
}

function assertAiProjectSearchRateLimit(
    mysqli $conn,
    string $clientHash,
    string $locale,
    int $requestChars
): void {
    $now = new DateTimeImmutable('now', appTimeZone());
    $minuteCutoff = $now->modify('-1 minute')->format('Y-m-d H:i:s');
    $hourCutoff = $now->modify('-1 hour')->format('Y-m-d H:i:s');

    $minuteCount = aiRequestCountSince($conn, 'project_search', $clientHash, $minuteCutoff);
    $hourCount = aiRequestCountSince($conn, 'project_search', $clientHash, $hourCutoff);

    if ($minuteCount >= aiProjectSearchRateLimitPerMinute() || $hourCount >= aiProjectSearchRateLimitPerHour()) {
        aiRecordBlockedRequest($conn, 'project_search', $clientHash, $locale, $requestChars);
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Too many project searches were made recently. Please try again shortly.',
        ], 429);
    }
}

function aiOpenAiUsageForToday(mysqli $conn): array
{
    $now = new DateTimeImmutable('now', appTimeZone());
    $start = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $end = $now->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS requests,
                COALESCE(SUM(input_tokens), 0) AS input_tokens,
                COALESCE(SUM(output_tokens), 0) AS output_tokens,
                COALESCE(SUM(total_tokens), 0) AS total_tokens
         FROM ai_request_events
         WHERE status = 'completed'
           AND provider = 'openai'
           AND created_at >= ?
           AND created_at < ?"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'requests' => max(0, (int) ($row['requests'] ?? 0)),
        'input_tokens' => max(0, (int) ($row['input_tokens'] ?? 0)),
        'output_tokens' => max(0, (int) ($row['output_tokens'] ?? 0)),
        'total_tokens' => max(0, (int) ($row['total_tokens'] ?? 0)),
    ];
}

function aiOpenAiDailyBudgetStatus(mysqli $conn): array
{
    $inputLimit = aiOpenAiDailyInputTokenLimit();
    $outputLimit = aiOpenAiDailyOutputTokenLimit();
    $usage = aiOpenAiUsageForToday($conn);

    $inputReached = $inputLimit > 0 && $usage['input_tokens'] >= $inputLimit;
    $outputReached = $outputLimit > 0 && $usage['output_tokens'] >= $outputLimit;

    return [
        'configured' => $inputLimit > 0 || $outputLimit > 0,
        'reached' => $inputReached || $outputReached,
        'input' => [
            'used' => $usage['input_tokens'],
            'limit' => $inputLimit,
            'remaining' => $inputLimit > 0 ? max(0, $inputLimit - $usage['input_tokens']) : null,
        ],
        'output' => [
            'used' => $usage['output_tokens'],
            'limit' => $outputLimit,
            'remaining' => $outputLimit > 0 ? max(0, $outputLimit - $usage['output_tokens']) : null,
        ],
        'requests' => $usage['requests'],
        'total_tokens' => $usage['total_tokens'],
    ];
}

function aiOpenAiProviderFailureCodesExcludedFromCircuit(): array
{
    return [
        'openai_disabled',
        'forced_local',
        'forced_local_test',
        'daily_token_budget',
        'provider_circuit_open',
        'local_selected',
    ];
}

function aiOpenAiCircuitStatus(mysqli $conn): array
{
    $threshold = aiOpenAiCircuitFailureThreshold();
    $windowMinutes = aiOpenAiCircuitWindowMinutes();
    $cooldownMinutes = aiOpenAiCircuitCooldownMinutes();

    if ($threshold <= 0) {
        return [
            'enabled' => false,
            'open' => false,
            'failure_threshold' => 0,
            'window_minutes' => $windowMinutes,
            'cooldown_minutes' => $cooldownMinutes,
            'recent_provider_failures' => 0,
            'retry_after_seconds' => 0,
        ];
    }

    $now = new DateTimeImmutable('now', appTimeZone());
    $lookbackMinutes = $windowMinutes + $cooldownMinutes;
    $cutoff = $now->modify("-{$lookbackMinutes} minutes")->format('Y-m-d H:i:s');

    $excluded = aiOpenAiProviderFailureCodesExcludedFromCircuit();
    $placeholders = implode(',', array_fill(0, count($excluded), '?'));
    $types = 's' . str_repeat('s', count($excluded));
    $params = array_merge([$cutoff], $excluded);

    $stmt = $conn->prepare(
        "SELECT error_code, created_at
         FROM ai_request_events
         WHERE status = 'completed'
           AND provider = 'local'
           AND error_code IS NOT NULL
           AND created_at >= ?
           AND error_code NOT IN ({$placeholders})
         ORDER BY created_at DESC
         LIMIT 100"
    );
    executePrepared($stmt, $types, $params);
    $result = $stmt->get_result();

    $failures = [];
    while ($row = $result->fetch_assoc()) {
        try {
            $failures[] = new DateTimeImmutable((string) $row['created_at'], appTimeZone());
        } catch (Throwable) {
            continue;
        }
    }
    $stmt->close();

    $open = false;
    $retryAfterSeconds = 0;

    foreach ($failures as $index => $latestFailure) {
        $clusterCount = 0;
        $clusterCutoff = $latestFailure->modify("-{$windowMinutes} minutes");

        for ($cursor = $index; $cursor < count($failures); $cursor++) {
            if ($failures[$cursor] < $clusterCutoff) {
                break;
            }
            $clusterCount++;
        }

        if ($clusterCount < $threshold) {
            continue;
        }

        $reopenAt = $latestFailure->modify("+{$cooldownMinutes} minutes");
        if ($reopenAt > $now) {
            $open = true;
            $retryAfterSeconds = max(1, $reopenAt->getTimestamp() - $now->getTimestamp());
            break;
        }
    }

    return [
        'enabled' => true,
        'open' => $open,
        'failure_threshold' => $threshold,
        'window_minutes' => $windowMinutes,
        'cooldown_minutes' => $cooldownMinutes,
        'recent_provider_failures' => count($failures),
        'retry_after_seconds' => $retryAfterSeconds,
    ];
}

function aiOpenAiOperationalDecision(mysqli $conn): array
{
    if (!aiEnabled()) {
        return [
            'allowed' => false,
            'reason' => 'openai_disabled',
            'budget' => null,
            'circuit' => null,
        ];
    }

    $budget = aiOpenAiDailyBudgetStatus($conn);
    if ($budget['reached']) {
        return [
            'allowed' => false,
            'reason' => 'daily_token_budget',
            'budget' => $budget,
            'circuit' => null,
        ];
    }

    $circuit = aiOpenAiCircuitStatus($conn);
    if ($circuit['open']) {
        return [
            'allowed' => false,
            'reason' => 'provider_circuit_open',
            'budget' => $budget,
            'circuit' => $circuit,
        ];
    }

    return [
        'allowed' => true,
        'reason' => null,
        'budget' => $budget,
        'circuit' => $circuit,
    ];
}

