<?php

declare(strict_types=1);

/**
 * Email delivery provider checks (Microsoft 365 Graph / SMTP).
 *
 *   php scripts/testMailTransport.php
 *       Offline checks: default provider, provider plan, message building and
 *       secret handling. Makes no network calls and sends nothing.
 *
 *   php scripts/testMailTransport.php --send-to=you@example.com
 *       Additionally sends one real test email through the provider currently
 *       selected in System Settings (or .env), exactly like the Admin
 *       "Send test email" button.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/emailService.php';

$failures = 0;
$check = static function (bool $condition, string $description) use (&$failures): void {
    echo ($condition ? '[OK] ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
};

$sendTo = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--send-to=')) {
        $sendTo = strtolower(trim(substr($argument, 10)));
    }
}

// Work on a copy of the environment so the offline checks never depend on, or
// change, the real configuration.
$realEnv = $_ENV;
$setEnv = static function (array $values): void {
    foreach ($values as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
};

try {
    $definitions = runtimeSettingsDefinitions();
    $check(($definitions['MAIL_TRANSPORT']['default'] ?? null) === 'graph',
        'MAIL_TRANSPORT defaults to Microsoft 365 (graph).');
    $check(($definitions['MAIL_TRANSPORT']['options'] ?? null) === ['graph', 'smtp'],
        'MAIL_TRANSPORT offers graph and smtp only.');
    $check(($definitions['MAIL_FALLBACK_TO_SMTP']['default'] ?? null) === false,
        'SMTP fallback is off by default.');
    foreach (['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET', 'SMTP_PASSWORD'] as $secretKey) {
        $check(!isset($definitions[$secretKey]), $secretKey . ' is not an admin-editable setting.');
    }

    $graphEnv = [
        'GRAPH_TENANT_ID' => 'contoso.onmicrosoft.com',
        'GRAPH_CLIENT_ID' => '00000000-0000-0000-0000-000000000000',
        'GRAPH_CLIENT_SECRET' => 'offline-test-secret-value',
        'GRAPH_SENDER' => 'notification@lambertelectromec.com',
    ];
    $smtpEnv = ['SMTP_HOST' => 'smtp.example.com', 'SMTP_FROM_EMAIL' => 'notification@lambertelectromec.com'];
    $noMail = array_fill_keys(array_merge(array_keys($graphEnv), array_keys($smtpEnv), ['MAIL_TRANSPORT', 'MAIL_FALLBACK_TO_SMTP']), null);

    // These checks use .env values only (no database overrides).
    $savedConn = $GLOBALS['conn'] ?? null;
    unset($GLOBALS['conn']);

    $setEnv($noMail);
    $check(mailTransportSelected() === 'graph', 'With nothing configured the selected provider is Microsoft 365.');
    $check(mailTransportPlan() === [], 'No provider is attempted when nothing is configured.');
    $check(mailTransportNotConfiguredMessage() === 'Microsoft 365 (Graph) is not configured.',
        'The not-configured message names Microsoft 365.');

    $setEnv($graphEnv);
    $check(graphMailConfigured(), 'Graph credentials are recognised.');
    $check(mailTransportPlan() === ['graph'], 'Graph only, when SMTP fallback is off.');

    $setEnv($smtpEnv + ['MAIL_FALLBACK_TO_SMTP' => 'true']);
    $check(mailTransportPlan() === ['graph', 'smtp'], 'Graph then SMTP when the fallback is enabled.');

    $setEnv(['MAIL_TRANSPORT' => 'smtp', 'MAIL_FALLBACK_TO_SMTP' => null]);
    $check(mailTransportSelected() === 'smtp' && mailTransportPlan() === ['smtp'], 'SMTP can be selected instead.');
    $check(mailTransportNotConfiguredMessage() === 'SMTP is not configured.', 'The not-configured message names SMTP.');

    $setEnv(['MAIL_TRANSPORT' => 'something-else']);
    $check(mailTransportSelected() === 'graph', 'An unknown provider value falls back to Microsoft 365.');

    $setEnv(['GRAPH_SENDER' => 'not-an-email']);
    $check(!graphMailConfigured(), 'An invalid GRAPH_SENDER is treated as not configured.');

    // Message building: the inline logo keeps its cid so the HTML template works.
    $setEnv($graphEnv + ['MAIL_TRANSPORT' => null]);
    $logo = contactEmailLogoPath();
    $message = lambertTestEmailMessage('admin@example.com', 'Test Admin');
    $resource = graphMailMessageResource($message, []);
    $check(($resource['toRecipients'][0]['emailAddress']['address'] ?? null) === 'admin@example.com',
        'Graph message has the recipient.');
    $check(($resource['body']['contentType'] ?? null) === 'HTML', 'Graph message body is HTML.');
    if ($logo !== null) {
        $inline = $resource['attachments'][0] ?? [];
        $check(($inline['isInline'] ?? false) === true && ($inline['contentId'] ?? null) === 'lambert-contact-logo'
            && str_contains((string) $resource['body']['content'], 'cid:lambert-contact-logo'),
            'Logo is sent as an inline attachment matching cid:lambert-contact-logo.');
    }
    $check(!str_contains(json_encode($resource, JSON_THROW_ON_ERROR), 'offline-test-secret-value'),
        'The client secret never appears in the message payload.');

    $expiry = (static function () use ($setEnv): array {
        $setEnv(['GRAPH_SECRET_EXPIRES' => (new DateTimeImmutable('+10 days'))->format('Y-m-d')]);
        $soon = runtimeSettingsGraphSecretExpirySummary();
        $setEnv(['GRAPH_SECRET_EXPIRES' => '2000-01-01']);
        $expired = runtimeSettingsGraphSecretExpirySummary();
        $setEnv(['GRAPH_SECRET_EXPIRES' => '2999-12-31']);
        $fine = runtimeSettingsGraphSecretExpirySummary();
        return [$soon, $expired, $fine];
    })();
    $check(($expiry[0]['status'] ?? null) === 'warning', 'A secret expiring within 30 days is flagged as a warning.');
    $check(($expiry[1]['status'] ?? null) === 'danger', 'An expired secret is flagged as danger.');
    $check(!isset($expiry[2]['status']), 'A secret with plenty of time left is not flagged.');

    // Restore the real configuration.
    $_ENV = $realEnv;
    foreach (array_keys($noMail + ['GRAPH_SECRET_EXPIRES' => null]) as $key) {
        if (array_key_exists($key, $realEnv)) {
            putenv($key . '=' . $realEnv[$key]);
        } else {
            putenv($key);
        }
    }
    if ($savedConn !== null) {
        $GLOBALS['conn'] = $savedConn;
    }

    if ($sendTo !== null) {
        if (!filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
            $check(false, 'A valid --send-to address is required.');
        } else {
            echo PHP_EOL . 'Sending a real test email through ' . mailTransportLabel(mailTransportSelected()) . ' to ' . $sendTo . '…' . PHP_EOL;
            $result = lambertSendEmail(lambertTestEmailMessage($sendTo));
            $check(true, 'Test email sent through ' . mailTransportLabel($result['provider'])
                . ($result['fallback_used'] ? ' (fallback used).' : '.'));
        }
    }
} catch (Throwable $error) {
    $check(false, 'Unhandled exception: ' . $error->getMessage());
}

if ($failures > 0) {
    echo PHP_EOL . $failures . ' email delivery test(s) failed.' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Email delivery provider tests passed.' . PHP_EOL;
exit(0);
