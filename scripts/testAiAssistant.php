<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/connection.php';
require_once $root . '/includes/aiAssistantService.php';

$message = trim((string) ($argv[1] ?? 'What services does Lambert Electromec provide?'));
$locale = strtolower(trim((string) ($argv[2] ?? 'en')));

try {
    $message = validatedAiAssistantMessage($message);
    $locale = validatedAiAssistantLocale($locale);
    $result = createLambertWebsiteAssistantResponse(
        $conn,
        $message,
        $locale,
        [],
        'cli_setup_test'
    );

    $assistant = (array) ($result['assistant'] ?? []);
    $provider = (array) ($result['provider'] ?? []);
    $usage = (array) ($provider['usage'] ?? []);

    $answer = (string) ($assistant['answer'] ?? '');
    $normalizedAnswer = aiTextNormalizeFinalAnswer($answer);
    if ($normalizedAnswer !== $answer) {
        throw new RuntimeException('Final answer was not normalized before return.');
    }

    $spacingDefects = aiTextKnownJoinedSpacingMatches($answer);
    if ($spacingDefects !== []) {
        throw new RuntimeException('Joined-word spacing remains in final assistant answer: ' . implode(', ', $spacingDefects));
    }

    echo "[OK] Lambert website assistant backend responded\n";
    echo "[OK] Final assistant answer spacing passed\n";
    echo 'Provider: ' . (string) ($provider['provider'] ?? (aiEnabled() ? aiProvider() : 'local')) . "\n";
    echo 'Model: ' . (string) ($provider['model'] ?? (aiEnabled() ? openAiModel() : 'lambert-knowledge-fallback-v1')) . "\n";
    echo 'Answer: ' . (string) ($assistant['answer'] ?? '') . "\n";

    $links = (array) ($assistant['links'] ?? []);
    if ($links !== []) {
        echo "Links:\n";
        foreach ($links as $link) {
            echo '- ' . (string) ($link['title'] ?? '') . ': ' . (string) ($link['url'] ?? '') . "\n";
        }
    }

    echo 'Input tokens: ' . (string) ($usage['input_tokens'] ?? 'n/a') . "\n";
    echo 'Output tokens: ' . (string) ($usage['output_tokens'] ?? 'n/a') . "\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
