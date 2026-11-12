<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/contentService.php';
require_once $root . '/includes/aiConfig.php';
require_once $root . '/includes/aiRequestService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(
    (string) envValue('DB_HOST', 'localhost'),
    (string) envValue('DB_USER', 'root'),
    (string) envValue('DB_PASSWORD', ''),
    (string) envValue('DB_NAME', 'lambert_electromec'),
    (int) envValue('DB_PORT', 3306)
);
$conn->set_charset('utf8mb4');
$dbTimeZone = trim((string) envValue('DB_TIME_ZONE', '+01:00'));
if ($dbTimeZone !== '') {
    $stmt = $conn->prepare('SET time_zone = ?');
    $stmt->bind_param('s', $dbTimeZone);
    $stmt->execute();
    $stmt->close();
}

$failures = [];
$ok = static function (string $message): void {
    echo '[OK] ' . $message . PHP_EOL;
};
$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
    echo '[FAIL] ' . $message . PHP_EOL;
};

$inputLimit = aiOpenAiDailyInputTokenLimit();
$outputLimit = aiOpenAiDailyOutputTokenLimit();
if ($inputLimit <= 0 && $outputLimit <= 0) {
    $fail('Daily OpenAI token guard is disabled.');
} else {
    $conn->begin_transaction();
    try {
        $existing = aiOpenAiUsageForToday($conn);
        $syntheticInput = $inputLimit > 0 ? max(1, $inputLimit - $existing['input_tokens']) : 0;
        $syntheticOutput = $outputLimit > 0 ? max(1, $outputLimit - $existing['output_tokens']) : 0;
        $syntheticTotal = $syntheticInput + $syntheticOutput;
        $hash = str_repeat('a', 64);
        $feature = 'guardrail_test';
        $locale = 'en';
        $status = 'completed';
        $provider = 'openai';
        $model = 'guardrail-test';

        $stmt = $conn->prepare(
            'INSERT INTO ai_request_events
                (feature, client_hash, locale, status, provider, model, request_chars,
                 input_tokens, output_tokens, total_tokens, latency_ms, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 1, NOW())'
        );
        $stmt->bind_param(
            'ssssssiii',
            $feature,
            $hash,
            $locale,
            $status,
            $provider,
            $model,
            $syntheticInput,
            $syntheticOutput,
            $syntheticTotal
        );
        $stmt->execute();
        $stmt->close();

        $budget = aiOpenAiDailyBudgetStatus($conn);
        if ((bool) ($budget['reached'] ?? false)) {
            $ok('Daily token guard switches OpenAI to fallback at the configured limit.');
        } else {
            $fail('Daily token guard did not detect the configured limit.');
        }
    } finally {
        $conn->rollback();
    }
}

$threshold = aiOpenAiCircuitFailureThreshold();
if ($threshold <= 0) {
    $fail('OpenAI circuit breaker is disabled.');
} else {
    $conn->begin_transaction();
    try {
        $hash = str_repeat('b', 64);
        $feature = 'guardrail_test';
        $locale = 'en';
        $status = 'completed';
        $provider = 'local';
        $model = 'lambert-guardrail-test';
        $error = 'simulated_provider_failure';

        $stmt = $conn->prepare(
            'INSERT INTO ai_request_events
                (feature, client_hash, locale, status, provider, model, request_chars, error_code, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())'
        );
        for ($i = 0; $i < $threshold; $i++) {
            $stmt->bind_param('sssssss', $feature, $hash, $locale, $status, $provider, $model, $error);
            $stmt->execute();
        }
        $stmt->close();

        $circuit = aiOpenAiCircuitStatus($conn);
        if ((bool) ($circuit['open'] ?? false)) {
            $ok('Circuit breaker opens after repeated provider failures.');
        } else {
            $fail('Circuit breaker did not open after the configured failure threshold.');
        }
    } finally {
        $conn->rollback();
    }
}

$conn->close();

if ($failures !== []) {
    echo PHP_EOL . count($failures) . " guardrail test(s) failed.\n";
    exit(1);
}

echo PHP_EOL . "Lambert AI operational guardrails passed. No external API calls were made.\n";
exit(0);
