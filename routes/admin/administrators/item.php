<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/adminUserService.php';

requireMethod('PATCH');
$admin = requireSuperAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();

$adminId = positiveInt($_GET['id'] ?? null, 'Administrator ID');
$existing = adminUserById($conn, $adminId);
if (!$existing) {
    jsonResponse(['status' => 'Failed', 'message' => 'Administrator not found.'], 404);
}

$body = requestJsonBody(50000);
$firstName = array_key_exists('first_name', $body)
    ? contentString($body['first_name'], 80, true, 'First name')
    : (string) $existing['first_name'];
$lastName = array_key_exists('last_name', $body)
    ? contentString($body['last_name'], 80, true, 'Last name')
    : (string) $existing['last_name'];
$email = array_key_exists('email', $body)
    ? normalizeAdminUserEmail($body['email'])
    : (string) $existing['email'];
$role = array_key_exists('role', $body)
    ? normalizeAdminUserRole($body['role'])
    : (string) $existing['role'];
$status = array_key_exists('status', $body)
    ? normalizeAdminUserStatus($body['status'])
    : (string) $existing['status'];

$isSelf = $adminId === (int) $admin['id'];
if ($isSelf) {
    if ($email !== (string) $existing['email'] || $role !== (string) $existing['role'] || $status !== (string) $existing['status']) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'For session safety, you cannot change your own email, role or status from Administrator Management.',
        ], 422);
    }
}

$removesActiveSuperAdmin = (string) $existing['role'] === 'super_admin'
    && (string) $existing['status'] === 'active'
    && ($role !== 'super_admin' || $status !== 'active');
if ($removesActiveSuperAdmin && activeSuperAdminCount($conn, $adminId) < 1) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'At least one active Super Admin account must remain.',
    ], 409);
}

$securitySensitive = $email !== (string) $existing['email']
    || $role !== (string) $existing['role']
    || $status !== (string) $existing['status'];
$tokenBump = $securitySensitive ? 1 : 0;

try {
    $stmt = $conn->prepare(
        'UPDATE admin_users
         SET first_name = ?, last_name = ?, email = ?, role = ?, status = ?, token_version = token_version + ?
         WHERE id = ?'
    );
    $stmt->bind_param('sssssii', $firstName, $lastName, $email, $role, $status, $tokenBump, $adminId);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'An administrator with that email already exists.'], 409);
    }
    throw $error;
}

if ($securitySensitive) {
    revokeAdminUserSessions($conn, $adminId);
}

recordAuditLog($conn, (int) $admin['id'], 'administrator.updated', 'admin_user', $adminId, [
    'role' => $role,
    'status' => $status,
    'security_sessions_revoked' => $securitySensitive,
]);

jsonResponse([
    'status' => 'Success',
    'message' => $securitySensitive
        ? 'Administrator updated and existing sessions revoked.'
        : 'Administrator updated successfully.',
    'data' => adminUserById($conn, $adminId),
]);
