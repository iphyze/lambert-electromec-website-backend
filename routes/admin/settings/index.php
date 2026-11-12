<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/runtimeSettingsService.php';

requireMethod('GET', 'PATCH');
$admin = requireSuperAdmin($conn);
applyNoStoreHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse([
        'status' => 'Success',
        'data' => runtimeSettingsAdminPayload($conn),
    ]);
}

requireCsrfToken();
$body = requestJsonBody(32768);
$settings = $body['settings'] ?? [];
$resetKeys = $body['reset_keys'] ?? [];

if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
    jsonResponse(['status' => 'Failed', 'message' => 'Settings must be an object.'], 422);
}
if (!is_array($resetKeys) || ($resetKeys !== [] && !array_is_list($resetKeys))) {
    jsonResponse(['status' => 'Failed', 'message' => 'reset_keys must be a list.'], 422);
}
foreach ($resetKeys as $resetKey) {
    if (!is_string($resetKey) && !is_numeric($resetKey)) {
        jsonResponse(['status' => 'Failed', 'message' => 'reset_keys contains an invalid item.'], 422);
    }
}

try {
    $result = updateRuntimeSettings($conn, (int) $admin['id'], $settings, $resetKeys);
} catch (InvalidArgumentException $error) {
    jsonResponse(['status' => 'Failed', 'message' => $error->getMessage()], 422);
}

recordAuditLog(
    $conn,
    (int) $admin['id'],
    'runtime_settings.update',
    'runtime_settings',
    null,
    [
        'changed_keys' => $result['changed_keys'],
        'reset_keys' => $result['reset_keys'],
    ]
);

jsonResponse([
    'status' => 'Success',
    'message' => 'System settings updated.',
    'data' => $result['payload'],
]);
