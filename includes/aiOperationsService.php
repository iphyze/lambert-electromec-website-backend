<?php

declare(strict_types=1);

require_once __DIR__ . '/aiConfig.php';
require_once __DIR__ . '/aiRequestService.php';
require_once __DIR__ . '/openAiService.php';

function aiOperationsRecentSummary(mysqli $conn, int $hours = 24): array
{
    $hours = max(1, min($hours, 168));
    $cutoff = (new DateTimeImmutable('now', appTimeZone()))
        ->modify("-{$hours} hours")
        ->format('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "SELECT feature, status, COALESCE(provider, 'unknown') AS provider,
                COUNT(*) AS requests,
                COALESCE(SUM(input_tokens), 0) AS input_tokens,
                COALESCE(SUM(output_tokens), 0) AS output_tokens,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                ROUND(COALESCE(AVG(latency_ms), 0)) AS average_latency_ms
         FROM ai_request_events
         WHERE created_at >= ?
         GROUP BY feature, status, provider
         ORDER BY feature ASC, status ASC, provider ASC"
    );
    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'feature' => (string) $row['feature'],
            'status' => (string) $row['status'],
            'provider' => (string) $row['provider'],
            'requests' => (int) $row['requests'],
            'input_tokens' => (int) $row['input_tokens'],
            'output_tokens' => (int) $row['output_tokens'],
            'total_tokens' => (int) $row['total_tokens'],
            'average_latency_ms' => (int) $row['average_latency_ms'],
        ];
    }
    $stmt->close();

    return $items;
}

function aiOperationsFallbackReasons(mysqli $conn, int $hours = 24): array
{
    $hours = max(1, min($hours, 168));
    $cutoff = (new DateTimeImmutable('now', appTimeZone()))
        ->modify("-{$hours} hours")
        ->format('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "SELECT error_code, COUNT(*) AS total
         FROM ai_request_events
         WHERE created_at >= ?
           AND status = 'completed'
           AND provider = 'local'
           AND error_code IS NOT NULL
         GROUP BY error_code
         ORDER BY total DESC, error_code ASC"
    );
    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'reason' => (string) $row['error_code'],
            'requests' => (int) $row['total'],
        ];
    }
    $stmt->close();

    return $items;
}

function aiOperationsStatus(mysqli $conn): array
{
    $budget = aiOpenAiDailyBudgetStatus($conn);
    $circuit = aiOpenAiCircuitStatus($conn);
    $decision = aiOpenAiOperationalDecision($conn);

    return [
        'openai' => [
            'enabled' => aiEnabled(),
            'provider' => aiProvider(),
            'model' => openAiModel(),
            'api_key_configured' => openAiApiKey() !== '' && strlen(openAiApiKey()) >= 20,
            'operational' => (bool) ($decision['allowed'] ?? false),
            'blocked_reason' => $decision['reason'] ?? null,
            'store_responses' => false,
        ],
        'fallbacks' => [
            'website_assistant' => aiAssistantFallbackEnabled(),
            'enquiry_intelligence' => aiEnquiryIntelligenceFallbackEnabled(),
            'project_search' => aiProjectSearchFallbackEnabled(),
        ],
        'daily_token_budget' => $budget,
        'provider_circuit' => $circuit,
        'request_event_retention_days' => aiRequestEventRetentionDays(),
        'privacy' => [
            'request_events_store_prompt_text' => false,
            'request_events_store_visitor_messages' => false,
            'openai_store_parameter' => false,
        ],
        'last_24_hours' => [
            'requests' => aiOperationsRecentSummary($conn, 24),
            'fallback_reasons' => aiOperationsFallbackReasons($conn, 24),
        ],
        'checked_at' => (new DateTimeImmutable('now', appTimeZone()))->format(DATE_ATOM),
    ];
}
