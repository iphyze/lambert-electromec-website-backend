<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This check must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/aiConfig.php';
require_once $root . '/includes/aiRequestService.php';
require_once $root . '/includes/aiAssistantService.php';
require_once $root . '/includes/aiProjectSearchService.php';
require_once $root . '/includes/contactAiIntelligenceService.php';

$strictProduction = in_array('--strict-production', $argv, true);
$checks = [];
$add = static function (string $level, string $name, string $detail = '') use (&$checks): void {
    $checks[] = compact('level', 'name', 'detail');
};

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
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
    $add('OK', 'Database connection');
} catch (Throwable $error) {
    $add('FAIL', 'Database connection', $error->getMessage());
    foreach ($checks as $check) {
        echo '[' . $check['level'] . '] ' . $check['name'] . ($check['detail'] !== '' ? ' — ' . $check['detail'] : '') . PHP_EOL;
    }
    exit(1);
}

$requiredAiTables = ['ai_knowledge_entries', 'ai_request_events', 'contact_ai_analyses', 'app_runtime_settings'];
$tableExists = [];
foreach ($requiredAiTables as $table) {
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    $tableExists[$table] = $result->num_rows === 1;
    $add($tableExists[$table] ? 'OK' : 'FAIL', 'AI table: ' . $table);
}

if ($tableExists['app_runtime_settings'] ?? false) {
    require_once $root . '/includes/runtimeSettingsService.php';
    $allowedRuntimeKeys = array_keys(runtimeSettingsDefinitions());
    $unknownRuntimeKeys = [];
    $result = $conn->query('SELECT setting_key FROM app_runtime_settings');
    while ($row = $result->fetch_assoc()) {
        $key = (string) ($row['setting_key'] ?? '');
        if ($key !== '' && !in_array($key, $allowedRuntimeKeys, true)) {
            $unknownRuntimeKeys[] = $key;
        }
    }
    $add(
        $unknownRuntimeKeys === [] ? 'OK' : 'WARN',
        'Runtime settings allow-list',
        $unknownRuntimeKeys === [] ? 'only approved non-secret settings can affect runtime' : 'ignored unknown keys: ' . implode(', ', $unknownRuntimeKeys)
    );
}

if ($tableExists['ai_request_events'] ?? false) {
    $rawContentColumns = [];
    $result = $conn->query('SHOW COLUMNS FROM ai_request_events');
    while ($row = $result->fetch_assoc()) {
        $field = strtolower((string) ($row['Field'] ?? ''));
        if (preg_match('/(?:prompt|message|content|input_text|output_text|conversation|email|phone)/', $field)) {
            $rawContentColumns[] = $field;
        }
    }
    $add(
        $rawContentColumns === [] ? 'OK' : 'FAIL',
        'AI request telemetry excludes raw conversation fields',
        $rawContentColumns === [] ? 'metadata/tokens only' : implode(', ', $rawContentColumns)
    );

    $budget = aiOpenAiDailyBudgetStatus($conn);
    $add(
        (bool) ($budget['reached'] ?? false) ? 'WARN' : 'OK',
        'Daily OpenAI token guard',
        ($budget['input']['used'] ?? 0) . '/' . ($budget['input']['limit'] ?? 0)
            . ' input; ' . ($budget['output']['used'] ?? 0) . '/' . ($budget['output']['limit'] ?? 0) . ' output'
    );

    $circuit = aiOpenAiCircuitStatus($conn);
    $add(
        (bool) ($circuit['open'] ?? false) ? 'WARN' : 'OK',
        'OpenAI circuit breaker',
        (bool) ($circuit['open'] ?? false)
            ? 'open; retry in ' . (int) ($circuit['retry_after_seconds'] ?? 0) . 's'
            : 'closed'
    );
}

foreach ([
    'Website assistant fallback' => aiAssistantFallbackEnabled(),
    'Enquiry Intelligence fallback' => aiEnquiryIntelligenceFallbackEnabled(),
    'Project Search fallback' => aiProjectSearchFallbackEnabled(),
] as $name => $enabled) {
    $add($enabled ? 'OK' : 'FAIL', $name, $enabled ? 'enabled' : 'disabled');
}

try {
    $assistant = createLambertWebsiteAssistantResponse(
        $conn,
        'What services does Lambert provide?',
        'en',
        [],
        'phase1_check',
        true
    );
    $assistantProvider = (string) ($assistant['provider']['provider'] ?? '');
    $add(
        $assistantProvider === 'local' ? 'OK' : 'FAIL',
        'Website assistant forced-local test',
        $assistantProvider === 'local' ? '0 external API calls' : 'unexpected provider: ' . $assistantProvider
    );
} catch (Throwable $error) {
    $add('FAIL', 'Website assistant forced-local test', $error->getMessage());
}

try {
    $projectSearch = createLambertAiProjectSearch(
        $conn,
        'Show Lambert power projects in Nigeria',
        'en',
        'phase1_check',
        true
    );
    $projectProvider = (string) ($projectSearch['provider']['provider'] ?? '');
    $add(
        $projectProvider === 'local' ? 'OK' : 'FAIL',
        'Project Search forced-local test',
        $projectProvider === 'local' ? '0 external API calls' : 'unexpected provider: ' . $projectProvider
    );
} catch (Throwable $error) {
    $add('FAIL', 'Project Search forced-local test', $error->getMessage());
}

try {
    $submissionResult = $conn->query('SELECT id FROM contact_submissions ORDER BY id DESC LIMIT 1');
    $submissionRow = $submissionResult->fetch_assoc();
    if ($submissionRow) {
        $submission = ensureContactSubmissionExists($conn, (int) $submissionRow['id']);
        $enquiry = createLambertEnquiryIntelligence($conn, $submission, true);
        $enquiryProvider = (string) ($enquiry['provider']['provider'] ?? '');
        $add(
            $enquiryProvider === 'local' ? 'OK' : 'FAIL',
            'Enquiry Intelligence forced-local test',
            $enquiryProvider === 'local' ? '0 external API calls; no analysis record written' : 'unexpected provider: ' . $enquiryProvider
        );
    } else {
        $add('WARN', 'Enquiry Intelligence forced-local test', 'no contact submission exists to test');
    }
} catch (Throwable $error) {
    $add('FAIL', 'Enquiry Intelligence forced-local test', $error->getMessage());
}

$environment = appEnvironment();
if ($strictProduction) {
    $add($environment === 'production' ? 'OK' : 'FAIL', 'APP_ENV production', $environment);
    $add(envBoolean('COOKIE_SECURE', true) ? 'OK' : 'FAIL', 'Secure cookies enabled');
    $hasLocalOrigin = false;
    foreach (allowedOrigins() as $origin) {
        if (str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1')) {
            $hasLocalOrigin = true;
            break;
        }
    }
    $add(!$hasLocalOrigin ? 'OK' : 'FAIL', 'Production CORS excludes localhost');
} else {
    $add('OK', 'Environment mode', $environment . ' (use --strict-production on the deployed server)');
}

$conn->close();

$failures = 0;
$warnings = 0;
foreach ($checks as $check) {
    if ($check['level'] === 'FAIL') $failures++;
    if ($check['level'] === 'WARN') $warnings++;
    echo '[' . $check['level'] . '] ' . $check['name'] . ($check['detail'] !== '' ? ' — ' . $check['detail'] : '') . PHP_EOL;
}

echo PHP_EOL;
if ($failures > 0) {
    echo $failures . " Phase 1 production check(s) failed.\n";
    exit(1);
}

echo 'Lambert AI Phase 1 production checks passed';
if ($warnings > 0) {
    echo " with {$warnings} warning(s)";
}
echo ".\n";
exit(0);
