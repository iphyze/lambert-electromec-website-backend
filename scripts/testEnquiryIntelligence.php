<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/connection.php';
require_once $root . '/includes/contactAiIntelligenceService.php';

$requestedId = isset($argv[1]) ? (int) $argv[1] : 0;

try {
    if ($requestedId > 0) {
        $submission = ensureContactSubmissionExists($conn, $requestedId);
    } else {
        $result = $conn->query('SELECT id FROM contact_submissions ORDER BY id DESC LIMIT 1');
        $row = $result->fetch_assoc();
        if (!$row) {
            throw new RuntimeException('No contact submission exists. Submit a test enquiry first or pass a submission ID.');
        }
        $submission = ensureContactSubmissionExists($conn, (int) $row['id']);
    }

    $analysisResult = createLambertEnquiryIntelligence($conn, $submission, false);
    $analysis = (array) ($analysisResult['analysis'] ?? []);
    $provider = (array) ($analysisResult['provider'] ?? []);
    $usage = (array) ($provider['usage'] ?? []);

    echo "[OK] Lambert Enquiry Intelligence responded\n";
    echo 'Submission ID: ' . (int) $submission['id'] . "\n";
    echo 'Provider: ' . (string) ($provider['provider'] ?? '') . "\n";
    echo 'Model: ' . (string) ($provider['model'] ?? '') . "\n";
    echo 'Summary: ' . (string) ($analysis['summary'] ?? '') . "\n";
    echo 'Classification: ' . (string) ($analysis['classification'] ?? '') . "\n";
    echo 'Priority: ' . (string) ($analysis['priority'] ?? '') . "\n";
    echo 'Commercial relevance: ' . (string) ($analysis['commercial_relevance'] ?? '') . "\n";
    echo 'Relevant division: ' . ((string) ($analysis['relevant_division'] ?? '') ?: 'n/a') . "\n";
    echo 'Relevant service: ' . ((string) ($analysis['relevant_service'] ?? '') ?: 'n/a') . "\n";
    echo 'Recommended next action: ' . (string) ($analysis['recommended_next_action'] ?? '') . "\n";
    echo "Suggested reply:\n" . (string) ($analysis['suggested_reply'] ?? '') . "\n";
    echo 'Input tokens: ' . (string) ($usage['input_tokens'] ?? 'n/a') . "\n";
    echo 'Output tokens: ' . (string) ($usage['output_tokens'] ?? 'n/a') . "\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
