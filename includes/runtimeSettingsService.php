<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function runtimeSettingsDefinitions(): array
{
    static $definitions = null;
    if (is_array($definitions)) {
        return $definitions;
    }

    $definitions = [
        'AI_ENABLED' => [
            'group' => 'ai_general',
            'group_label' => 'AI General',
            'label' => 'Use OpenAI',
            'description' => 'When disabled, Lambert uses the local fallback engines only.',
            'type' => 'boolean',
            'default' => false,
        ],
        'OPENAI_MODEL' => [
            'group' => 'ai_general',
            'group_label' => 'AI General',
            'label' => 'OpenAI model',
            'description' => 'Server-side model used for Lambert AI features.',
            'type' => 'string',
            'default' => 'gpt-5.6-luna',
            'max_length' => 100,
            'pattern' => '/^[A-Za-z0-9._:-]{2,100}$/',
        ],
        'OPENAI_REASONING_EFFORT' => [
            'group' => 'ai_general',
            'group_label' => 'AI General',
            'label' => 'Reasoning effort',
            'description' => 'Controls model reasoning effort where supported.',
            'type' => 'select',
            'default' => 'low',
            'options' => ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
        ],
        'AI_REQUEST_EVENT_RETENTION_DAYS' => [
            'group' => 'ai_general',
            'group_label' => 'AI General',
            'label' => 'AI telemetry retention',
            'description' => 'Days to retain metadata-only AI request events.',
            'type' => 'integer',
            'default' => 14,
            'min' => 1,
            'max' => 90,
            'suffix' => 'days',
        ],

        'AI_OPENAI_DAILY_INPUT_TOKEN_LIMIT' => [
            'group' => 'ai_guardrails',
            'group_label' => 'OpenAI Guardrails',
            'label' => 'Daily input-token limit',
            'description' => 'At the limit, Lambert automatically switches to local fallbacks. Set 0 to disable the cap.',
            'type' => 'integer',
            'default' => 500000,
            'min' => 0,
            'max' => 100000000,
        ],
        'AI_OPENAI_DAILY_OUTPUT_TOKEN_LIMIT' => [
            'group' => 'ai_guardrails',
            'group_label' => 'OpenAI Guardrails',
            'label' => 'Daily output-token limit',
            'description' => 'At the limit, Lambert automatically switches to local fallbacks. Set 0 to disable the cap.',
            'type' => 'integer',
            'default' => 100000,
            'min' => 0,
            'max' => 25000000,
        ],
        'AI_OPENAI_CIRCUIT_FAILURE_THRESHOLD' => [
            'group' => 'ai_guardrails',
            'group_label' => 'OpenAI Guardrails',
            'label' => 'Circuit-breaker failure threshold',
            'description' => 'Consecutive provider failures before OpenAI is temporarily bypassed. Set 0 to disable.',
            'type' => 'integer',
            'default' => 3,
            'min' => 0,
            'max' => 50,
        ],
        'AI_OPENAI_CIRCUIT_WINDOW_MINUTES' => [
            'group' => 'ai_guardrails',
            'group_label' => 'OpenAI Guardrails',
            'label' => 'Circuit observation window',
            'description' => 'Time window used to count provider failures.',
            'type' => 'integer',
            'default' => 5,
            'min' => 1,
            'max' => 60,
            'suffix' => 'min',
        ],
        'AI_OPENAI_CIRCUIT_COOLDOWN_MINUTES' => [
            'group' => 'ai_guardrails',
            'group_label' => 'OpenAI Guardrails',
            'label' => 'Circuit cooldown',
            'description' => 'How long to use local fallbacks after the circuit opens.',
            'type' => 'integer',
            'default' => 10,
            'min' => 1,
            'max' => 120,
            'suffix' => 'min',
        ],

        'AI_ASSISTANT_FALLBACK_ENABLED' => [
            'group' => 'website_assistant',
            'group_label' => 'Website Assistant',
            'label' => 'Local fallback',
            'description' => 'Keeps the public assistant available without OpenAI.',
            'type' => 'boolean',
            'default' => true,
        ],
        'AI_ASSISTANT_SOURCE_LIMIT' => [
            'group' => 'website_assistant',
            'group_label' => 'Website Assistant',
            'label' => 'Retrieved source limit',
            'description' => 'Maximum approved Lambert sources sent to the assistant per request.',
            'type' => 'integer',
            'default' => 8,
            'min' => 3,
            'max' => 14,
        ],
        'AI_ASSISTANT_MAX_OUTPUT_TOKENS' => [
            'group' => 'website_assistant',
            'group_label' => 'Website Assistant',
            'label' => 'Maximum output tokens',
            'description' => 'Caps the response size for the public assistant.',
            'type' => 'integer',
            'default' => 500,
            'min' => 128,
            'max' => 1500,
        ],
        'AI_ASSISTANT_RATE_LIMIT_PER_MINUTE' => [
            'group' => 'website_assistant',
            'group_label' => 'Website Assistant',
            'label' => 'Requests per minute',
            'description' => 'Per-visitor abuse-control limit.',
            'type' => 'integer',
            'default' => 8,
            'min' => 1,
            'max' => 60,
        ],
        'AI_ASSISTANT_RATE_LIMIT_PER_HOUR' => [
            'group' => 'website_assistant',
            'group_label' => 'Website Assistant',
            'label' => 'Requests per hour',
            'description' => 'Per-visitor abuse-control limit.',
            'type' => 'integer',
            'default' => 60,
            'min' => 5,
            'max' => 500,
        ],

        'AI_ENQUIRY_INTELLIGENCE_FALLBACK_ENABLED' => [
            'group' => 'enquiry_intelligence',
            'group_label' => 'Enquiry Intelligence',
            'label' => 'Local fallback',
            'description' => 'Keeps enquiry analysis available without OpenAI.',
            'type' => 'boolean',
            'default' => true,
        ],
        'AI_ENQUIRY_MAX_OUTPUT_TOKENS' => [
            'group' => 'enquiry_intelligence',
            'group_label' => 'Enquiry Intelligence',
            'label' => 'Maximum output tokens',
            'description' => 'Caps OpenAI output used for enquiry analysis and reply suggestions.',
            'type' => 'integer',
            'default' => 650,
            'min' => 192,
            'max' => 1800,
        ],
        'AI_ENQUIRY_RATE_LIMIT_PER_HOUR' => [
            'group' => 'enquiry_intelligence',
            'group_label' => 'Enquiry Intelligence',
            'label' => 'Analyses per hour',
            'description' => 'Per-admin Enquiry Intelligence usage limit.',
            'type' => 'integer',
            'default' => 60,
            'min' => 5,
            'max' => 300,
        ],

        'AI_PROJECT_SEARCH_FALLBACK_ENABLED' => [
            'group' => 'project_search',
            'group_label' => 'AI Project Search',
            'label' => 'Local fallback',
            'description' => 'Keeps natural-language project discovery available without OpenAI.',
            'type' => 'boolean',
            'default' => true,
        ],
        'AI_PROJECT_SEARCH_RESULT_LIMIT' => [
            'group' => 'project_search',
            'group_label' => 'AI Project Search',
            'label' => 'Maximum results',
            'description' => 'Maximum real CMS projects returned per AI search.',
            'type' => 'integer',
            'default' => 12,
            'min' => 1,
            'max' => 24,
        ],
        'AI_PROJECT_SEARCH_MAX_OUTPUT_TOKENS' => [
            'group' => 'project_search',
            'group_label' => 'AI Project Search',
            'label' => 'Maximum output tokens',
            'description' => 'Caps the OpenAI intent-interpretation response.',
            'type' => 'integer',
            'default' => 260,
            'min' => 128,
            'max' => 700,
        ],
        'AI_PROJECT_SEARCH_RATE_LIMIT_PER_MINUTE' => [
            'group' => 'project_search',
            'group_label' => 'AI Project Search',
            'label' => 'Searches per minute',
            'description' => 'Per-visitor AI project-search limit.',
            'type' => 'integer',
            'default' => 12,
            'min' => 1,
            'max' => 60,
        ],
        'AI_PROJECT_SEARCH_RATE_LIMIT_PER_HOUR' => [
            'group' => 'project_search',
            'group_label' => 'AI Project Search',
            'label' => 'Searches per hour',
            'description' => 'Per-visitor AI project-search limit.',
            'type' => 'integer',
            'default' => 120,
            'min' => 10,
            'max' => 1000,
        ],

        'MAIL_TRANSPORT' => [
            'group' => 'email_delivery',
            'group_label' => 'Email Delivery',
            'label' => 'Email delivery provider',
            'description' => 'Provider used to send contact and careers notification emails. Credentials are kept in the server .env.',
            'type' => 'select',
            'default' => 'graph',
            'options' => ['graph', 'smtp'],
            'option_labels' => [
                'graph' => 'Microsoft 365 (Graph API)',
                'smtp' => 'SMTP',
            ],
        ],
        'MAIL_FALLBACK_TO_SMTP' => [
            'group' => 'email_delivery',
            'group_label' => 'Email Delivery',
            'label' => 'Fall back to SMTP if Microsoft 365 fails',
            'description' => 'When Microsoft 365 is selected and a message cannot be sent, retry once through SMTP (only if SMTP is configured in .env).',
            'type' => 'boolean',
            'default' => false,
        ],

        'ANALYTICS_ENABLED' => [
            'group' => 'website_analytics',
            'group_label' => 'Website Analytics',
            'label' => 'Collect website statistics',
            'description' => 'Counts visits to the public website with anonymous, cookie-free statistics. Turning this off stops collection; existing statistics stay available.',
            'type' => 'boolean',
            'default' => true,
        ],
        'ANALYTICS_RESPECT_DNT' => [
            'group' => 'website_analytics',
            'group_label' => 'Website Analytics',
            'label' => 'Respect Do Not Track / Global Privacy Control',
            'description' => 'Visitors whose browser asks not to be tracked are not counted.',
            'type' => 'boolean',
            'default' => true,
        ],
        'ANALYTICS_RAW_RETENTION_DAYS' => [
            'group' => 'website_analytics',
            'group_label' => 'Website Analytics',
            'label' => 'Detailed statistics retention',
            'description' => 'Detailed visit records are kept this long; daily totals are kept permanently.',
            'type' => 'integer',
            'default' => 400,
            'min' => 30,
            'max' => 730,
            'suffix' => 'days',
        ],
        'ANALYTICS_COLLECT_RATE_LIMIT_PER_MINUTE' => [
            'group' => 'website_analytics',
            'group_label' => 'Website Analytics',
            'label' => 'Statistics rate limit per visitor',
            'description' => 'Maximum page views and actions recorded per anonymous visitor each minute (protects against abuse).',
            'type' => 'integer',
            'default' => 120,
            'min' => 20,
            'max' => 1000,
            'suffix' => '/min',
        ],

        'CONTACT_EMAIL_ATTACH_CV' => [
            'group' => 'contact_protection',
            'group_label' => 'Contact Workflow',
            'label' => 'Attach CV to routed email',
            'description' => 'Controls whether an uploaded CV is attached to the internal notification email.',
            'type' => 'boolean',
            'default' => true,
        ],
        'CONTACT_RATE_LIMIT_WINDOW_MINUTES' => [
            'group' => 'contact_protection',
            'group_label' => 'Contact Workflow',
            'label' => 'IP rate-limit window',
            'description' => 'Window used for public contact-form submissions from one IP hash.',
            'type' => 'integer',
            'default' => 15,
            'min' => 1,
            'max' => 1440,
            'suffix' => 'min',
        ],
        'CONTACT_RATE_LIMIT_MAX' => [
            'group' => 'contact_protection',
            'group_label' => 'Contact Workflow',
            'label' => 'Maximum submissions per IP window',
            'description' => 'Maximum public submissions allowed within the IP rate-limit window.',
            'type' => 'integer',
            'default' => 5,
            'min' => 1,
            'max' => 100,
        ],
        'CONTACT_EMAIL_RATE_WINDOW_MINUTES' => [
            'group' => 'contact_protection',
            'group_label' => 'Contact Workflow',
            'label' => 'Email rate-limit window',
            'description' => 'Window used to limit repeated submissions from one email address.',
            'type' => 'integer',
            'default' => 60,
            'min' => 1,
            'max' => 1440,
            'suffix' => 'min',
        ],
        'CONTACT_EMAIL_RATE_MAX' => [
            'group' => 'contact_protection',
            'group_label' => 'Contact Workflow',
            'label' => 'Maximum submissions per email window',
            'description' => 'Maximum public submissions allowed from one email address within the configured window.',
            'type' => 'integer',
            'default' => 8,
            'min' => 1,
            'max' => 100,
        ],
        'CONTACT_DUPLICATE_WINDOW_MINUTES' => [
            'group' => 'contact_protection',
            'group_label' => 'Contact Workflow',
            'label' => 'Duplicate-detection window',
            'description' => 'Period during which an identical contact submission is treated as a duplicate.',
            'type' => 'integer',
            'default' => 10,
            'min' => 1,
            'max' => 1440,
            'suffix' => 'min',
        ],
    ];

    return $definitions;
}

function runtimeSettingsConnection(): ?mysqli
{
    $conn = $GLOBALS['conn'] ?? null;
    return $conn instanceof mysqli ? $conn : null;
}

function runtimeSettingsOverrides(?mysqli $conn = null): array
{
    $conn ??= runtimeSettingsConnection();
    if (!$conn instanceof mysqli) {
        return [];
    }

    $cacheKey = 'lambert_runtime_settings_cache_' . spl_object_id($conn);
    if (isset($GLOBALS[$cacheKey]) && is_array($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    try {
        $result = $conn->query('SELECT setting_key, setting_value FROM app_runtime_settings');
        $overrides = [];
        while ($row = $result->fetch_assoc()) {
            $overrides[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        $GLOBALS[$cacheKey] = $overrides;
        return $overrides;
    } catch (Throwable) {
        return [];
    }
}

function resetRuntimeSettingsCache(?mysqli $conn = null): void
{
    $conn ??= runtimeSettingsConnection();
    if ($conn instanceof mysqli) {
        unset($GLOBALS['lambert_runtime_settings_cache_' . spl_object_id($conn)]);
    }
}

function runtimeSettingValue(string $key, mixed $default = null): mixed
{
    $definitions = runtimeSettingsDefinitions();
    if (!isset($definitions[$key])) {
        return envValue($key, $default);
    }

    $overrides = runtimeSettingsOverrides();
    if (array_key_exists($key, $overrides)) {
        return $overrides[$key];
    }

    return envValue($key, $default ?? ($definitions[$key]['default'] ?? null));
}

function runtimeSettingBoolean(string $key, bool $default): bool
{
    $value = runtimeSettingValue($key, $default ? 'true' : 'false');
    if (is_bool($value)) {
        return $value;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function runtimeSettingsNormalizeTypedValue(array $definition, mixed $value): bool|int|string
{
    $type = (string) ($definition['type'] ?? 'string');

    if ($type === 'boolean') {
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InvalidArgumentException('Expected a true/false value.');
        }
        return $parsed;
    }

    if ($type === 'integer') {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw new InvalidArgumentException('Expected a whole number.');
        }
        $parsed = (int) $parsed;
        $minimum = (int) ($definition['min'] ?? PHP_INT_MIN);
        $maximum = (int) ($definition['max'] ?? PHP_INT_MAX);
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new InvalidArgumentException("Value must be between {$minimum} and {$maximum}.");
        }
        return $parsed;
    }

    $string = trim((string) $value);
    if ($type === 'select') {
        $options = array_map('strval', (array) ($definition['options'] ?? []));
        if (!in_array($string, $options, true)) {
            throw new InvalidArgumentException('Unsupported option.');
        }
        return $string;
    }

    $maxLength = (int) ($definition['max_length'] ?? 500);
    if ($string === '' || mb_strlen($string) > $maxLength) {
        throw new InvalidArgumentException("Value is required and must not exceed {$maxLength} characters.");
    }
    $pattern = $definition['pattern'] ?? null;
    if (is_string($pattern) && preg_match($pattern, $string) !== 1) {
        throw new InvalidArgumentException('Value format is invalid.');
    }

    return $string;
}

function runtimeSettingsSerializeValue(bool|int|string $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    return (string) $value;
}

function runtimeSettingsResolvedItems(mysqli $conn): array
{
    $definitions = runtimeSettingsDefinitions();
    $overrides = runtimeSettingsOverrides($conn);
    $groups = [];

    foreach ($definitions as $key => $definition) {
        $default = $definition['default'] ?? null;
        $envValueRaw = envValue($key, $default);
        try {
            $envTyped = runtimeSettingsNormalizeTypedValue($definition, $envValueRaw);
        } catch (Throwable) {
            $envTyped = runtimeSettingsNormalizeTypedValue($definition, $default);
        }

        $hasOverride = array_key_exists($key, $overrides);
        $resolvedRaw = $hasOverride ? $overrides[$key] : $envTyped;
        try {
            $resolvedTyped = runtimeSettingsNormalizeTypedValue($definition, $resolvedRaw);
        } catch (Throwable) {
            $resolvedTyped = $envTyped;
            $hasOverride = false;
        }

        $groupKey = (string) $definition['group'];
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'key' => $groupKey,
                'label' => (string) $definition['group_label'],
                'items' => [],
            ];
        }

        $groups[$groupKey]['items'][] = [
            'key' => $key,
            'label' => (string) $definition['label'],
            'description' => (string) $definition['description'],
            'type' => (string) $definition['type'],
            'value' => $resolvedTyped,
            'env_value' => $envTyped,
            'source' => $hasOverride ? 'admin_override' : 'env',
            'min' => $definition['min'] ?? null,
            'max' => $definition['max'] ?? null,
            'suffix' => $definition['suffix'] ?? null,
            'options' => $definition['options'] ?? null,
            'option_labels' => $definition['option_labels'] ?? null,
        ];
    }

    return array_values($groups);
}

function runtimeSettingsGraphCredentialsConfigured(): bool
{
    foreach (['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET', 'GRAPH_SENDER'] as $key) {
        if (trim((string) envValue($key, '')) === '') {
            return false;
        }
    }

    return filter_var(trim((string) envValue('GRAPH_SENDER', '')), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Read-only expiry row for the Graph client secret. Only the date is shown;
 * the secret itself never leaves the server. status is 'warning' when fewer
 * than 30 days remain and 'danger' once the date has passed.
 */
function runtimeSettingsGraphSecretExpirySummary(): array
{
    $row = ['label' => 'Graph secret expiry', 'value' => 'Not set', 'protected' => true];
    $raw = trim((string) envValue('GRAPH_SECRET_EXPIRES', ''));
    if ($raw === '') {
        return $row;
    }

    try {
        $zone = function_exists('appTimeZone') ? appTimeZone() : new DateTimeZone((string) envValue('APP_TIMEZONE', 'Africa/Lagos'));
    } catch (Throwable) {
        $zone = new DateTimeZone('UTC');
    }
    $expires = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zone);
    if ($expires === false) {
        return $row + ['status' => 'warning', 'value' => 'Invalid date (use YYYY-MM-DD)'];
    }

    $today = new DateTimeImmutable('today', $zone);
    $days = (int) $today->diff($expires)->format('%r%a');
    $label = $expires->format('j M Y');

    if ($days < 0) {
        return array_merge($row, ['value' => $label . ' (expired)', 'status' => 'danger']);
    }
    if ($days < 30) {
        return array_merge($row, ['value' => $label . ' (' . $days . ' days left)', 'status' => 'warning']);
    }

    return array_merge($row, ['value' => $label . ' (' . $days . ' days left)']);
}

function runtimeSettingsEnvironmentSummary(): array
{
    return [
        ['label' => 'Application environment', 'value' => appEnvironment(), 'protected' => true],
        ['label' => 'Application timezone', 'value' => (string) envValue('APP_TIMEZONE', 'Africa/Lagos'), 'protected' => true],
        ['label' => 'Public API URL', 'value' => (string) envValue('PUBLIC_API_URL', ''), 'protected' => true],
        ['label' => 'OpenAI API key', 'value' => strlen(trim((string) envValue('OPENAI_API_KEY', ''))) >= 20 ? 'Configured' : 'Not configured', 'protected' => true],
        ['label' => 'Microsoft 365 Graph credentials', 'value' => runtimeSettingsGraphCredentialsConfigured() ? 'Configured' : 'Not configured', 'protected' => true],
        ['label' => 'Graph sender', 'value' => trim((string) envValue('GRAPH_SENDER', '')) !== '' ? trim((string) envValue('GRAPH_SENDER', '')) : 'Not configured', 'protected' => true],
        runtimeSettingsGraphSecretExpirySummary(),
        ['label' => 'SMTP credentials', 'value' => trim((string) envValue('SMTP_HOST', '')) !== '' ? 'Configured' : 'Not configured', 'protected' => true],
        ['label' => 'Statistics site hosts', 'value' => trim((string) envValue('ANALYTICS_ALLOWED_HOSTS', '')) !== '' ? trim((string) envValue('ANALYTICS_ALLOWED_HOSTS', '')) : 'From ALLOWED_ORIGINS', 'protected' => true],
        ['label' => 'Statistics country source', 'value' => envBoolean('ANALYTICS_TRUST_CLOUDFLARE_COUNTRY', false) ? 'Cloudflare' : 'Not collected', 'protected' => true],
        ['label' => 'Turnstile secret', 'value' => trim((string) envValue('TURNSTILE_SECRET_KEY', '')) !== '' ? 'Configured' : 'Not configured', 'protected' => true],
        ['label' => 'JWT / application secrets', 'value' => 'Protected in .env', 'protected' => true],
        ['label' => 'Database credentials', 'value' => 'Protected in .env', 'protected' => true],
    ];
}

function runtimeSettingsAdminPayload(mysqli $conn): array
{
    return [
        'groups' => runtimeSettingsResolvedItems($conn),
        'environment' => runtimeSettingsEnvironmentSummary(),
        'note' => 'Admin values override the matching .env value at runtime. Resetting a setting returns control to .env.',
    ];
}

function updateRuntimeSettings(mysqli $conn, int $adminId, array $settings, array $resetKeys): array
{
    $definitions = runtimeSettingsDefinitions();
    $changedKeys = [];
    $resetApplied = [];

    if (count($settings) > 50 || count($resetKeys) > 50) {
        throw new InvalidArgumentException('Too many settings were submitted at once.');
    }

    $conn->begin_transaction();
    try {
        $upsert = $conn->prepare(
            'INSERT INTO app_runtime_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
        );

        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (!isset($definitions[$key])) {
                throw new InvalidArgumentException('Unsupported setting: ' . $key);
            }
            $typedValue = runtimeSettingsNormalizeTypedValue($definitions[$key], $value);
            $serialized = runtimeSettingsSerializeValue($typedValue);
            $upsert->bind_param('ssi', $key, $serialized, $adminId);
            $upsert->execute();
            $changedKeys[] = $key;
        }
        $upsert->close();

        $delete = $conn->prepare('DELETE FROM app_runtime_settings WHERE setting_key = ?');
        foreach ($resetKeys as $key) {
            $key = (string) $key;
            if (!isset($definitions[$key])) {
                throw new InvalidArgumentException('Unsupported reset setting: ' . $key);
            }
            $delete->bind_param('s', $key);
            $delete->execute();
            $resetApplied[] = $key;
        }
        $delete->close();

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    resetRuntimeSettingsCache($conn);

    return [
        'changed_keys' => array_values(array_unique($changedKeys)),
        'reset_keys' => array_values(array_unique($resetApplied)),
        'payload' => runtimeSettingsAdminPayload($conn),
    ];
}
