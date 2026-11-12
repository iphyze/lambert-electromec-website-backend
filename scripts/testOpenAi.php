<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "[FAIL] vendor/autoload.php was not found. Run composer install first.\n");
    exit(1);
}

require_once $autoload;
require_once $root . '/includes/openAiService.php';

try {
    $result = openAiCreateTextResponse(
        'You are performing a server connection test. Reply with exactly: Lambert AI connection OK.',
        'Connection test.',
        [
            'max_output_tokens' => 40,
            'reasoning_effort' => 'none',
            'metadata' => ['feature' => 'connection_test'],
        ]
    );

    echo '[OK] OpenAI connection successful' . PHP_EOL;
    echo 'Model: ' . ($result['model'] ?? 'unknown') . PHP_EOL;
    echo 'Response ID: ' . ($result['response_id'] ?? 'not supplied') . PHP_EOL;
    echo 'Text: ' . ($result['text'] ?? '') . PHP_EOL;

    $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
    if ($usage !== []) {
        echo 'Input tokens: ' . (string) ($usage['input_tokens'] ?? 'n/a') . PHP_EOL;
        echo 'Output tokens: ' . (string) ($usage['output_tokens'] ?? 'n/a') . PHP_EOL;
    }

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
