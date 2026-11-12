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
    $result = createLambertAiProjectSearch($conn, $query, $locale, 'cli_project_search_fallback_test', true);
    $search = (array) ($result['search'] ?? []);
    $provider = (array) ($result['provider'] ?? []);

    if (($provider['provider'] ?? '') !== 'local') {
        throw new RuntimeException('Fallback test unexpectedly used an external provider.');
    }

    echo "[OK] Lambert AI Project Search local fallback responded without calling OpenAI\n";
    echo 'Provider: ' . (string) ($provider['provider'] ?? '') . "\n";
    echo 'Model: ' . (string) ($provider['model'] ?? '') . "\n";
    echo 'Query: ' . (string) ($search['query'] ?? '') . "\n";
    echo 'Results: ' . (int) ($search['result_count'] ?? 0) . "\n";

    foreach ((array) ($search['items'] ?? []) as $project) {
        echo '- ' . (string) ($project['title'] ?? '')
            . ' | ' . (string) ($project['location'] ?? '')
            . ' | /projects/' . (string) ($project['slug'] ?? '') . "\n";
    }

    echo "External API calls: 0\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
