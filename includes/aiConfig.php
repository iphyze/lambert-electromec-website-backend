<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/runtimeSettingsService.php';

function aiConfigInt(string $key, int $default, int $minimum, int $maximum): int
{
    $value = filter_var(runtimeSettingValue($key, $default), FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }

    return max($minimum, min($maximum, (int) $value));
}

function aiKnowledgeQueryMaxChars(): int
{
    return aiConfigInt('AI_KNOWLEDGE_QUERY_MAX_CHARS', 500, 50, 2000);
}

function aiKnowledgeResultLimit(): int
{
    return aiConfigInt('AI_KNOWLEDGE_RESULT_LIMIT', 18, 4, 40);
}

function aiKnowledgePerSourceLimit(): int
{
    return aiConfigInt('AI_KNOWLEDGE_PER_SOURCE_LIMIT', 6, 1, 15);
}

function aiKnowledgeSearchTokenLimit(): int
{
    return aiConfigInt('AI_KNOWLEDGE_SEARCH_TOKEN_LIMIT', 8, 2, 16);
}

function aiKnowledgeLocale(string $locale): string
{
    return strtolower(trim($locale)) === 'fr' ? 'fr' : 'en';
}

function validatedAiKnowledgeQuery(mixed $value): string
{
    if (!is_string($value) && !is_numeric($value)) {
        throw new InvalidArgumentException('AI knowledge query must be text.');
    }

    $query = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    if ($query === '') {
        throw new InvalidArgumentException('AI knowledge query is required.');
    }

    if (mb_strlen($query) > aiKnowledgeQueryMaxChars()) {
        throw new InvalidArgumentException(
            'AI knowledge query must not exceed ' . aiKnowledgeQueryMaxChars() . ' characters.'
        );
    }

    return $query;
}

function aiAssistantMessageMaxChars(): int
{
    return aiConfigInt('AI_ASSISTANT_MESSAGE_MAX_CHARS', 700, 100, 2000);
}

function aiAssistantHistoryMaxTurns(): int
{
    return aiConfigInt('AI_ASSISTANT_HISTORY_MAX_TURNS', 6, 0, 12);
}

function aiAssistantHistoryItemMaxChars(): int
{
    return aiConfigInt('AI_ASSISTANT_HISTORY_ITEM_MAX_CHARS', 800, 100, 4000);
}

function aiAssistantSourceLimit(): int
{
    return aiConfigInt('AI_ASSISTANT_SOURCE_LIMIT', 8, 3, 14);
}

function aiAssistantContextMaxChars(): int
{
    return aiConfigInt('AI_ASSISTANT_CONTEXT_MAX_CHARS', 12000, 2000, 30000);
}

function aiAssistantContextItemMaxChars(): int
{
    return aiConfigInt('AI_ASSISTANT_CONTEXT_ITEM_MAX_CHARS', 2800, 500, 6000);
}

function aiAssistantMaxOutputTokens(): int
{
    return aiConfigInt('AI_ASSISTANT_MAX_OUTPUT_TOKENS', 500, 128, 1500);
}

function aiAssistantAnswerMaxChars(): int
{
    return aiConfigInt('AI_ASSISTANT_ANSWER_MAX_CHARS', 2400, 300, 6000);
}

function aiAssistantRequestMaxBytes(): int
{
    return aiConfigInt('AI_ASSISTANT_REQUEST_MAX_BYTES', 16384, 4096, 65536);
}

function aiAssistantRateLimitPerMinute(): int
{
    return aiConfigInt('AI_ASSISTANT_RATE_LIMIT_PER_MINUTE', 8, 1, 60);
}

function aiAssistantRateLimitPerHour(): int
{
    return aiConfigInt('AI_ASSISTANT_RATE_LIMIT_PER_HOUR', 60, 5, 500);
}

function aiAssistantFallbackEnabled(): bool
{
    return runtimeSettingBoolean('AI_ASSISTANT_FALLBACK_ENABLED', true);
}

function aiAssistantFallbackSourceLimit(): int
{
    return aiConfigInt('AI_ASSISTANT_FALLBACK_SOURCE_LIMIT', 3, 1, 6);
}

function aiAssistantFallbackExcerptChars(): int
{
    return aiConfigInt('AI_ASSISTANT_FALLBACK_EXCERPT_CHARS', 520, 180, 1200);
}

function aiAssistantAvailable(): bool
{
    return aiEnabled() || aiAssistantFallbackEnabled();
}

function aiEnquiryIntelligenceFallbackEnabled(): bool
{
    return runtimeSettingBoolean('AI_ENQUIRY_INTELLIGENCE_FALLBACK_ENABLED', true);
}

function aiEnquiryIntelligenceAvailable(): bool
{
    return aiEnabled() || aiEnquiryIntelligenceFallbackEnabled();
}

function aiEnquiryMaxOutputTokens(): int
{
    return aiConfigInt('AI_ENQUIRY_MAX_OUTPUT_TOKENS', 650, 192, 1800);
}

function aiEnquiryContextSourceLimit(): int
{
    return aiConfigInt('AI_ENQUIRY_CONTEXT_SOURCE_LIMIT', 4, 0, 8);
}

function aiEnquiryContextMaxChars(): int
{
    return aiConfigInt('AI_ENQUIRY_CONTEXT_MAX_CHARS', 6000, 1000, 16000);
}

function aiEnquirySummaryMaxChars(): int
{
    return aiConfigInt('AI_ENQUIRY_SUMMARY_MAX_CHARS', 420, 120, 1000);
}

function aiEnquirySuggestedReplyMaxChars(): int
{
    return aiConfigInt('AI_ENQUIRY_SUGGESTED_REPLY_MAX_CHARS', 2200, 400, 5000);
}

function aiEnquiryRateLimitPerHour(): int
{
    return aiConfigInt('AI_ENQUIRY_RATE_LIMIT_PER_HOUR', 60, 5, 300);
}

function aiProjectSearchFallbackEnabled(): bool
{
    return runtimeSettingBoolean('AI_PROJECT_SEARCH_FALLBACK_ENABLED', true);
}

function aiProjectSearchAvailable(): bool
{
    return aiEnabled() || aiProjectSearchFallbackEnabled();
}

function aiProjectSearchQueryMaxChars(): int
{
    return aiConfigInt('AI_PROJECT_SEARCH_QUERY_MAX_CHARS', 500, 50, 1200);
}

function aiProjectSearchResultLimit(): int
{
    return aiConfigInt('AI_PROJECT_SEARCH_RESULT_LIMIT', 12, 1, 24);
}

function aiProjectSearchMaxOutputTokens(): int
{
    return aiConfigInt('AI_PROJECT_SEARCH_MAX_OUTPUT_TOKENS', 260, 128, 700);
}

function aiProjectSearchRequestMaxBytes(): int
{
    return aiConfigInt('AI_PROJECT_SEARCH_REQUEST_MAX_BYTES', 8192, 2048, 32768);
}

function aiProjectSearchRateLimitPerMinute(): int
{
    return aiConfigInt('AI_PROJECT_SEARCH_RATE_LIMIT_PER_MINUTE', 12, 1, 60);
}

function aiProjectSearchRateLimitPerHour(): int
{
    return aiConfigInt('AI_PROJECT_SEARCH_RATE_LIMIT_PER_HOUR', 120, 10, 1000);
}

function aiRequestEventRetentionDays(): int
{
    return aiConfigInt('AI_REQUEST_EVENT_RETENTION_DAYS', 14, 1, 90);
}

function aiOpenAiDailyInputTokenLimit(): int
{
    return aiConfigInt('AI_OPENAI_DAILY_INPUT_TOKEN_LIMIT', 500000, 0, 100000000);
}

function aiOpenAiDailyOutputTokenLimit(): int
{
    return aiConfigInt('AI_OPENAI_DAILY_OUTPUT_TOKEN_LIMIT', 100000, 0, 25000000);
}

function aiOpenAiCircuitFailureThreshold(): int
{
    return aiConfigInt('AI_OPENAI_CIRCUIT_FAILURE_THRESHOLD', 3, 0, 50);
}

function aiOpenAiCircuitWindowMinutes(): int
{
    return aiConfigInt('AI_OPENAI_CIRCUIT_WINDOW_MINUTES', 5, 1, 60);
}

function aiOpenAiCircuitCooldownMinutes(): int
{
    return aiConfigInt('AI_OPENAI_CIRCUIT_COOLDOWN_MINUTES', 10, 1, 120);
}

function aiEnabled(): bool
{
    return runtimeSettingBoolean('AI_ENABLED', false);
}

function aiProvider(): string
{
    $provider = strtolower(trim((string) runtimeSettingValue('AI_PROVIDER', 'openai')));
    if ($provider !== 'openai') {
        throw new RuntimeException('Unsupported AI provider configured.', 500);
    }

    return $provider;
}

function openAiApiUrl(): string
{
    return 'https://api.openai.com/v1/responses';
}

function openAiApiKey(): string
{
    return trim((string) envValue('OPENAI_API_KEY', ''));
}

function openAiModel(): string
{
    $model = trim((string) runtimeSettingValue('OPENAI_MODEL', 'gpt-5.6-luna'));
    if ($model === '' || preg_match('/^[A-Za-z0-9._:-]{2,100}$/', $model) !== 1) {
        throw new RuntimeException('OPENAI_MODEL is invalid.', 500);
    }

    return $model;
}

function openAiReasoningEffort(): string
{
    $effort = strtolower(trim((string) runtimeSettingValue('OPENAI_REASONING_EFFORT', 'low')));
    $allowed = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    return in_array($effort, $allowed, true) ? $effort : 'low';
}

function openAiMaxOutputTokens(): int
{
    return aiConfigInt('OPENAI_MAX_OUTPUT_TOKENS', 700, 32, 8000);
}

function openAiMaxInputChars(): int
{
    return aiConfigInt('OPENAI_MAX_INPUT_CHARS', 24000, 1000, 120000);
}

function openAiConnectTimeoutSeconds(): int
{
    return aiConfigInt('OPENAI_CONNECT_TIMEOUT_SECONDS', 5, 2, 15);
}

function openAiTimeoutSeconds(): int
{
    return aiConfigInt('OPENAI_TIMEOUT_SECONDS', 25, 5, 90);
}

function openAiRetryCount(): int
{
    return aiConfigInt('OPENAI_RETRY_COUNT', 1, 0, 2);
}

function openAiMaxResponseBytes(): int
{
    return aiConfigInt('OPENAI_MAX_RESPONSE_BYTES', 1048576, 65536, 4194304);
}

function openAiOrganization(): string
{
    return trim((string) envValue('OPENAI_ORGANIZATION', ''));
}

function openAiProject(): string
{
    return trim((string) envValue('OPENAI_PROJECT', ''));
}
