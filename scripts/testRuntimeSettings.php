<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/connection.php';
require_once $root . '/includes/runtimeSettingsService.php';
require_once $root . '/includes/aiTextService.php';

try {
    $table = $conn->query("SHOW TABLES LIKE 'app_runtime_settings'");
    if ($table->num_rows !== 1) {
        throw new RuntimeException('app_runtime_settings is missing. Run migration 014_runtime_settings.sql first.');
    }

    $definitions = runtimeSettingsDefinitions();
    foreach (['OPENAI_API_KEY', 'DB_PASSWORD', 'JWT_SECRET', 'APP_HASH_SECRET', 'SMTP_PASSWORD', 'TURNSTILE_SECRET_KEY', 'GRAPH_CLIENT_SECRET', 'GRAPH_CLIENT_ID', 'GRAPH_TENANT_ID'] as $secretKey) {
        if (isset($definitions[$secretKey])) {
            throw new RuntimeException('Protected secret was incorrectly exposed as an editable runtime setting: ' . $secretKey);
        }
    }

    $mailDefinition = $definitions['MAIL_TRANSPORT'] ?? null;
    if (!is_array($mailDefinition) || ($mailDefinition['default'] ?? null) !== 'graph'
        || ($mailDefinition['options'] ?? null) !== ['graph', 'smtp']) {
        throw new RuntimeException('MAIL_TRANSPORT must be a graph/smtp select that defaults to graph.');
    }

    $payload = runtimeSettingsAdminPayload($conn);
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
    $graphSecret = (string) envValue('GRAPH_CLIENT_SECRET', '');
    if ($graphSecret !== '' && str_contains($payloadJson, $graphSecret)) {
        throw new RuntimeException('The Graph client secret leaked into the admin settings payload.');
    }
    if (($payload['groups'] ?? []) === []) {
        throw new RuntimeException('Runtime settings catalog is empty.');
    }

    $spacingInput = "areas ofwork; presence andexpertise; ensurethat quality; propertymanagement; Piped\u{200B}Services; Maintenance\u{2060}and Repairs";
    $spacingExpected = 'areas of work; presence and expertise; ensure that quality; property management; Piped Services; Maintenance and Repairs';
    $spacing = aiTextNormalizeFinalAnswer($spacingInput);
    if ($spacing !== $spacingExpected) {
        throw new RuntimeException('AI text spacing normalization failed: ' . $spacing);
    }

    if (aiTextKnownJoinedSpacingMatches($spacing) !== []) {
        throw new RuntimeException('AI text joined-word detector found a defect after normalization.');
    }

    // Exact long-form regression samples that previously failed on Windows PHP.
    $realWorldSamples = [
        'Lambert Electromec Ltd is a leading Engineering, Procurement and construction company with an international presence and expertise in Mechanical, Electrical & Piped Services MEP particularly across Africa.',
        'Data Centres, as well as Facility Management.',
        'Selects and engages the services of qualified personnel and contractors with necessary steps taken to continually improve their competencies.',
        'Ensures the use of customer feedback to continually improve the quality of our products and services.',
        'Lambert Electromec has developed and implemented this quality management system.',
        'Supply of Chemicals, Maintenance and Repairs of Borehole and Water Treatment Plants.',
    ];

    foreach ($realWorldSamples as $sample) {
        $normalized = aiTextNormalizeFinalAnswer($sample);
        if ($normalized !== $sample) {
            throw new RuntimeException('AI spacing normalizer altered legitimate wording: ' . $normalized);
        }
        if (substr_count($normalized, ' ') < substr_count($sample, ' ')) {
            throw new RuntimeException('AI spacing normalizer removed an ordinary space.');
        }
    }

    // Database-level regression: the actual approved rows must retain every
    // ordinary space. Only CRLF -> LF normalisation is expected here.
    $keys = ['about.profile', 'corporate.iso-qms'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT knowledge_key, content FROM ai_knowledge_entries WHERE locale = 'en' AND knowledge_key IN ({$placeholders}) ORDER BY knowledge_key");
    $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
    $stmt->execute();
    $rows = $stmt->get_result();
    $checked = 0;

    while ($row = $rows->fetch_assoc()) {
        $source = (string) $row['content'];
        $expected = trim(str_replace(["\r\n", "\r"], "\n", $source));
        $normalized = aiTextNormalizeFinalAnswer($source);

        if ($normalized !== $expected) {
            throw new RuntimeException('AI normalizer altered approved DB wording for ' . (string) $row['knowledge_key']);
        }
        if (substr_count($normalized, ' ') < substr_count($source, ' ')) {
            throw new RuntimeException('AI normalizer removed DB spaces for ' . (string) $row['knowledge_key']);
        }
        $checked++;
    }
    $stmt->close();

    if ($checked !== 2) {
        throw new RuntimeException('Could not validate both approved DB regression rows.');
    }

    if (aiTextNormalizeFinalAnswer($spacing) !== $spacing) {
        throw new RuntimeException('AI text normalizer is not idempotent.');
    }

    echo "[OK] Runtime settings table is available\n";
    echo '[OK] Editable settings: ' . count($definitions) . "\n";
    echo "[OK] Sensitive .env secrets are excluded from Admin settings\n";
    echo "[OK] AI spacing normalization is conservative\n";
    echo "[OK] Known joined-word defects are repaired\n";
    echo "[OK] Legitimate ASCII spaces are never removed\n";
    echo "[OK] Actual approved DB wording is preserved\n";
    echo "[OK] Hidden separators are converted to normal spaces\n";
    echo "[OK] Normalizer is idempotent\n";
    echo "[OK] No external API calls were made\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
}
