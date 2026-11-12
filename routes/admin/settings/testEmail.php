<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/emailService.php';

// Sends a short test email through the provider currently saved in
// System Settings (Microsoft 365 Graph by default, or SMTP).
requireMethod('POST');
$admin = requireSuperAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();

$body = requestJsonBody(4096);
$recipient = strtolower(trim((string) ($body['recipient'] ?? '')));
if ($recipient === '') {
    $recipient = strtolower(trim((string) ($admin['email'] ?? '')));
}
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || mb_strlen($recipient) > 190) {
    jsonResponse(['status' => 'Failed', 'message' => 'Enter a valid recipient email address.'], 422);
}

// At most 5 test emails per administrator every 15 minutes.
$cutoff = (new DateTimeImmutable('-15 minutes', appTimeZone()))->format('Y-m-d H:i:s');
$adminId = (int) $admin['id'];
$action = 'runtime_settings.test_email';
$stmt = $conn->prepare('SELECT COUNT(*) AS total FROM audit_logs WHERE admin_user_id = ? AND action = ? AND created_at >= ?');
$stmt->bind_param('iss', $adminId, $action, $cutoff);
$stmt->execute();
$recent = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();
if ($recent >= 5) {
    jsonResponse(['status' => 'Failed', 'message' => 'Too many test emails. Please wait a few minutes and try again.'], 429);
}

$selected = mailTransportSelected();
$name = trim((string) ($admin['first_name'] ?? '') . ' ' . (string) ($admin['last_name'] ?? ''));

try {
    $result = lambertSendEmail(lambertTestEmailMessage($recipient, $recipient === strtolower((string) ($admin['email'] ?? '')) ? $name : ''));
} catch (Throwable $error) {
    recordAuditLog($conn, $adminId, $action, 'runtime_settings', null, [
        'selected_provider' => $selected,
        'result' => 'failed',
    ]);
    jsonResponse([
        'status' => 'Failed',
        'message' => 'The test email could not be sent. ' . mb_substr($error->getMessage(), 0, 400),
        'data' => ['provider' => $selected],
    ], 502);
}

recordAuditLog($conn, $adminId, $action, 'runtime_settings', null, [
    'selected_provider' => $selected,
    'provider' => $result['provider'],
    'fallback_used' => $result['fallback_used'],
    'result' => 'sent',
]);

$providerLabel = mailTransportLabel($result['provider']);
jsonResponse([
    'status' => 'Success',
    'message' => $result['fallback_used']
        ? 'Microsoft 365 failed, so the test email was sent through SMTP to ' . $recipient . '.'
        : 'Test email sent through ' . $providerLabel . ' to ' . $recipient . '.',
    'data' => [
        'provider' => $result['provider'],
        'provider_label' => $providerLabel,
        'fallback_used' => $result['fallback_used'],
        'recipient' => $recipient,
    ],
]);
