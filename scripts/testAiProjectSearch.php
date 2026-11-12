<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/connection.php';
require_once $root . '/includes/aiProjectSearchService.php';

$query = trim((string) ($argv[1] ?? 'Show me Lambert data center projects in Nigeria.'));
$locale = strtolower(trim((string) ($argv[2] ?? 'en')));

try {
    $query = validatedAiProjectSearchQuery($query);
    $locale = validatedAiProjectSearchLocale($locale);
    $result = createLambertAiProjectSearch($conn, $query, $locale, 'cli_project_search_test');
    $search = (array) ($result['search'] ?? []);
    $provider = (array) ($result['provider'] ?? []);

    echo "[OK] Lambert AI Project Search responded\n";
    echo 'Provider: ' . (string) ($provider['provider'] ?? '') . "\n";
    echo 'Model: ' . (string) ($provider['model'] ?? '') . "\n";
    echo 'Query: ' . (string) ($search['query'] ?? '') . "\n";
    echo 'Intent: ' . (string) ($search['summary'] ?? '') . "\n";
    echo 'Results: ' . (int) ($search['result_count'] ?? 0) . "\n";

    foreach ((array) ($search['items'] ?? []) as $project) {
        echo '- ' . (string) ($project['title'] ?? '')
            . ' | ' . (string) ($project['location'] ?? '')
            . ' | /projects/' . (string) ($project['slug'] ?? '') . "\n";
    }

    $usage = (array) ($provider['usage'] ?? []);
    if ($usage !== []) {
        echo 'Input tokens: ' . (int) ($usage['input_tokens'] ?? 0) . "\n";
        echo 'Output tokens: ' . (int) ($usage['output_tokens'] ?? 0) . "\n";
    }

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
