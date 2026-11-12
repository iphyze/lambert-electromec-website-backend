<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This verification script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "[FAIL] vendor/autoload.php was not found. Run composer install first.\n");
    exit(1);
}

require_once $autoload;
require_once $root . '/includes/security.php';
require_once $root . '/includes/turnstileService.php';
require_once $root . '/includes/aiConfig.php';
require_once $root . '/includes/aiRequestService.php';
require_once $root . '/includes/emailService.php';

$checks = [];
$add = static function (string $name, bool $healthy, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'healthy' => $healthy, 'detail' => $detail];
};

$add('PHP >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);
foreach (['mysqli', 'mbstring', 'json', 'fileinfo', 'dom', 'curl', 'zip'] as $extension) {
    $add('Extension: ' . $extension, extension_loaded($extension));
}

foreach ([
    'Firebase\\JWT\\JWT' => 'firebase/php-jwt',
    'Dotenv\\Dotenv' => 'vlucas/phpdotenv',
    'Respect\\Validation\\Validator' => 'respect/validation',
    'PHPMailer\\PHPMailer\\PHPMailer' => 'phpmailer/phpmailer',
] as $class => $package) {
    $add('Composer: ' . $package, class_exists($class));
}

$jwtSecret = trim((string) envValue('JWT_SECRET', ''));
$add('JWT_SECRET configured', strlen($jwtSecret) >= 32, 'minimum 32 random characters');
$appHashSecret = trim((string) envValue('APP_HASH_SECRET', ''));
$add('APP_HASH_SECRET configured', strlen($appHashSecret) >= 32, 'minimum 32 random characters');
$add('DB_NAME configured', trim((string) envValue('DB_NAME', '')) !== '');
$add('DB_USER configured', trim((string) envValue('DB_USER', '')) !== '');
$add('PUBLIC_API_URL configured', trim((string) envValue('PUBLIC_API_URL', '')) !== '');

try {
    $provider = aiProvider();
    $add('AI provider: OpenAI', $provider === 'openai', aiEnabled() ? 'enabled' : 'disabled');
} catch (Throwable $error) {
    $add('AI provider: OpenAI', false, $error->getMessage());
}

$add(
    'AI assistant local fallback',
    aiAssistantFallbackEnabled(),
    aiAssistantFallbackEnabled() ? 'enabled' : 'disabled'
);
$add('AI assistant available', aiAssistantAvailable(), aiEnabled() ? 'OpenAI + local fallback' : 'local fallback only');

if (aiEnabled()) {
    $openAiKey = openAiApiKey();
    $add(
        'OPENAI_API_KEY configured',
        strlen($openAiKey) >= 20 && !str_contains(strtolower($openAiKey), 'replace'),
        'server-side only'
    );

    try {
        $model = openAiModel();
        $add('OPENAI_MODEL configured', $model !== '', $model);
    } catch (Throwable $error) {
        $add('OPENAI_MODEL configured', false, $error->getMessage());
    }

    $add('OpenAI HTTPS endpoint enforced', str_starts_with(openAiApiUrl(), 'https://api.openai.com/'));
    $add('OpenAI input limit configured', openAiMaxInputChars() >= 1000, (string) openAiMaxInputChars());
    $add('OpenAI output limit configured', openAiMaxOutputTokens() >= 32, (string) openAiMaxOutputTokens());
    $add(
        'OpenAI daily input token guard configured',
        aiOpenAiDailyInputTokenLimit() > 0,
        (string) aiOpenAiDailyInputTokenLimit()
    );
    $add(
        'OpenAI daily output token guard configured',
        aiOpenAiDailyOutputTokenLimit() > 0,
        (string) aiOpenAiDailyOutputTokenLimit()
    );
    $add(
        'OpenAI provider circuit breaker configured',
        aiOpenAiCircuitFailureThreshold() > 0,
        aiOpenAiCircuitFailureThreshold() . ' failures / ' . aiOpenAiCircuitWindowMinutes()
            . ' min; cooldown ' . aiOpenAiCircuitCooldownMinutes() . ' min'
    );
}

if (aiAssistantAvailable()) {
    $add('AI assistant message limit configured', aiAssistantMessageMaxChars() >= 100, (string) aiAssistantMessageMaxChars());
    $add('AI assistant source limit configured', aiAssistantSourceLimit() >= 3, (string) aiAssistantSourceLimit());
    $add('AI assistant context limit configured', aiAssistantContextMaxChars() >= 2000, (string) aiAssistantContextMaxChars());
    $add('AI fallback source limit configured', aiAssistantFallbackSourceLimit() >= 1, (string) aiAssistantFallbackSourceLimit());
    $add('AI fallback excerpt limit configured', aiAssistantFallbackExcerptChars() >= 180, (string) aiAssistantFallbackExcerptChars());
    $add('AI assistant minute rate limit configured', aiAssistantRateLimitPerMinute() >= 1, (string) aiAssistantRateLimitPerMinute());
    $add('AI assistant hourly rate limit configured', aiAssistantRateLimitPerHour() >= 5, (string) aiAssistantRateLimitPerHour());
}

$add(
    'AI Enquiry Intelligence local fallback',
    aiEnquiryIntelligenceFallbackEnabled(),
    aiEnquiryIntelligenceFallbackEnabled() ? 'enabled' : 'disabled'
);
$add(
    'AI Enquiry Intelligence available',
    aiEnquiryIntelligenceAvailable(),
    aiEnabled() ? 'OpenAI + local fallback' : 'local fallback only'
);
if (aiEnquiryIntelligenceAvailable()) {
    $add('AI Enquiry output limit configured', aiEnquiryMaxOutputTokens() >= 192, (string) aiEnquiryMaxOutputTokens());
    $add('AI Enquiry context source limit configured', aiEnquiryContextSourceLimit() >= 0, (string) aiEnquiryContextSourceLimit());
    $add('AI Enquiry context limit configured', aiEnquiryContextMaxChars() >= 1000, (string) aiEnquiryContextMaxChars());
    $add('AI Enquiry hourly rate limit configured', aiEnquiryRateLimitPerHour() >= 5, (string) aiEnquiryRateLimitPerHour());
}

$add(
    'AI Project Search local fallback',
    aiProjectSearchFallbackEnabled(),
    aiProjectSearchFallbackEnabled() ? 'enabled' : 'disabled'
);
$add(
    'AI Project Search available',
    aiProjectSearchAvailable(),
    aiEnabled() ? 'OpenAI + local fallback' : 'local fallback only'
);
if (aiProjectSearchAvailable()) {
    $add('AI Project Search query limit configured', aiProjectSearchQueryMaxChars() >= 50, (string) aiProjectSearchQueryMaxChars());
    $add('AI Project Search result limit configured', aiProjectSearchResultLimit() >= 1, (string) aiProjectSearchResultLimit());
    $add('AI Project Search output limit configured', aiProjectSearchMaxOutputTokens() >= 128, (string) aiProjectSearchMaxOutputTokens());
    $add('AI Project Search minute rate limit configured', aiProjectSearchRateLimitPerMinute() >= 1, (string) aiProjectSearchRateLimitPerMinute());
    $add('AI Project Search hourly rate limit configured', aiProjectSearchRateLimitPerHour() >= 10, (string) aiProjectSearchRateLimitPerHour());
}

$origins = allowedOrigins();
$add('ALLOWED_ORIGINS configured', $origins !== [], implode(', ', $origins));
if (appEnvironment() === 'production') {
    $add('Turnstile required', turnstileRequired());
    $add('TURNSTILE_SECRET_KEY configured', trim((string) envValue('TURNSTILE_SECRET_KEY', '')) !== '');
    // Check the credentials of the email provider selected in System Settings
    // (Microsoft 365 Graph by default), plus SMTP when it is the selected
    // provider or the Graph fallback is enabled.
    $mailTransport = mailTransportSelected();
    $add('Email delivery provider', true, mailTransportLabel($mailTransport));
    if ($mailTransport === 'graph') {
        foreach (['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET'] as $graphKey) {
            $add($graphKey . ' configured', trim((string) envValue($graphKey, '')) !== '');
        }
        $graphSender = trim((string) envValue('GRAPH_SENDER', ''));
        $add('GRAPH_SENDER configured', filter_var($graphSender, FILTER_VALIDATE_EMAIL) !== false, $graphSender);
        $expiry = runtimeSettingsGraphSecretExpirySummary();
        $add('Graph client secret not expiring soon', ($expiry['status'] ?? null) === null && $expiry['value'] !== 'Not set', (string) $expiry['value']);
    }
    if ($mailTransport === 'smtp' || mailFallbackToSmtpEnabled()) {
        $add('SMTP_HOST configured', trim((string) envValue('SMTP_HOST', '')) !== '');
        $smtpFrom = trim((string) envValue('SMTP_FROM_EMAIL', ''));
        $add('SMTP_FROM_EMAIL configured', $smtpFrom !== '' && filter_var($smtpFrom, FILTER_VALIDATE_EMAIL) !== false);
        if (envBoolean('SMTP_AUTH', true)) {
            $add('SMTP_USERNAME configured', trim((string) envValue('SMTP_USERNAME', '')) !== '');
            $add('SMTP_PASSWORD configured', (string) envValue('SMTP_PASSWORD', '') !== '');
        }
    }
    $add('TURNSTILE_ALLOWED_HOSTNAMES configured', configuredTurnstileHostnames() !== []);

    $hasLocalOrigin = false;
    foreach ($origins as $origin) {
        if (str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1')) {
            $hasLocalOrigin = true;
            break;
        }
    }
    $add('Production CORS excludes localhost', !$hasLocalOrigin);
    $add('Production cookies are Secure', envBoolean('COOKIE_SECURE', true));
}

foreach (['projects', 'media-center', 'cv'] as $directoryName) {
    $directory = $root . '/uploads/' . $directoryName;
    $add('Upload directory exists: ' . $directoryName, is_dir($directory), $directory);
    $add('Upload directory writable: ' . $directoryName, is_dir($directory) && is_writable($directory));
}

$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(
        (string) envValue('DB_HOST', 'localhost'),
        (string) envValue('DB_USER', 'root'),
        (string) envValue('DB_PASSWORD', ''),
        (string) envValue('DB_NAME', 'lambert_electromec'),
        (int) envValue('DB_PORT', 3306)
    );
    $conn->set_charset('utf8mb4');
    $add('Database connection', true);
} catch (Throwable $error) {
    $add('Database connection', false, $error->getMessage());
}

if ($conn instanceof mysqli) {
    $requiredTables = [
        'admin_users',
        'auth_refresh_tokens',
        'auth_login_attempts',
        'audit_logs',
        'app_runtime_settings',
        'divisions',
        'projects',
        'project_images',
        'media_categories',
        'media_items',
        'contact_subjects',
        'contact_submissions',
        'contact_attachments',
        'contact_email_deliveries',
        'contact_ai_analyses',
        'ai_knowledge_entries',
        'ai_request_events',
    ];

    $result = $conn->query('SHOW TABLES');
    $existing = [];
    while ($row = $result->fetch_row()) {
        $existing[] = (string) $row[0];
    }

    foreach ($requiredTables as $table) {
        $add('Table: ' . $table, in_array($table, $existing, true));
    }

    if (in_array('ai_knowledge_entries', $existing, true)) {
        $knowledgeResult = $conn->query(
            "SELECT locale, COUNT(*) AS total
             FROM ai_knowledge_entries
             WHERE status = 'active'
             GROUP BY locale"
        );
        $knowledgeCounts = ['en' => 0, 'fr' => 0];
        while ($knowledgeRow = $knowledgeResult->fetch_assoc()) {
            $locale = (string) ($knowledgeRow['locale'] ?? '');
            if (array_key_exists($locale, $knowledgeCounts)) {
                $knowledgeCounts[$locale] = (int) ($knowledgeRow['total'] ?? 0);
            }
        }
        $add('AI approved knowledge: English', $knowledgeCounts['en'] > 0, (string) $knowledgeCounts['en']);
        $add('AI approved knowledge: French', $knowledgeCounts['fr'] > 0, (string) $knowledgeCounts['fr']);
    }

    if (in_array('ai_request_events', $existing, true)) {
        try {
            $budget = aiOpenAiDailyBudgetStatus($conn);
            $add(
                'OpenAI daily token guard readable',
                true,
                ((bool) ($budget['reached'] ?? false) ? 'limit reached; local fallback active — ' : '')
                    . ($budget['input']['used'] ?? 0) . '/' . ($budget['input']['limit'] ?? 0)
                    . ' input; ' . ($budget['output']['used'] ?? 0) . '/' . ($budget['output']['limit'] ?? 0) . ' output'
            );

            $circuit = aiOpenAiCircuitStatus($conn);
            $add(
                'OpenAI provider circuit readable',
                true,
                (bool) ($circuit['open'] ?? false)
                    ? 'open; local fallback active; retry in ' . (int) ($circuit['retry_after_seconds'] ?? 0) . 's'
                    : 'closed'
            );
        } catch (Throwable $error) {
            $add('AI operational guardrails readable', false, $error->getMessage());
        }
    }

    if (in_array('contact_subjects', $existing, true)) {
        $routingResult = $conn->query(
            "SELECT COUNT(*) AS total,
                    SUM(recipient_emails IS NOT NULL AND TRIM(recipient_emails) NOT IN ('', '[]')) AS configured
             FROM contact_subjects
             WHERE status = 'active'"
        );
        $routing = $routingResult->fetch_assoc() ?: [];
        $configured = (int) ($routing['configured'] ?? 0);
        $total = (int) ($routing['total'] ?? 0);
        $add('Active contact subjects exist', $total > 0, (string) $total);
        if (appEnvironment() === 'production') {
            $add('All active contact subjects have recipients', $total > 0 && $configured === $total, $configured . '/' . $total);
        }
    }

    $conn->close();
}

$failed = array_values(array_filter($checks, static fn (array $check): bool => !$check['healthy']));
foreach ($checks as $check) {
    $prefix = $check['healthy'] ? '[OK]  ' : '[FAIL]';
    $detail = $check['detail'] !== '' ? ' — ' . $check['detail'] : '';
    echo $prefix . ' ' . $check['name'] . $detail . PHP_EOL;
}

echo PHP_EOL;
if ($failed === []) {
    echo "Lambert backend setup is healthy.\n";
    exit(0);
}

echo count($failed) . " setup check(s) failed. Fix them before production deployment.\n";
exit(1);
