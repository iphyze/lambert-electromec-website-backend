<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/adminUserService.php';

requireMethod('POST');
$admin = requireSuperAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody(50000);

$adminId = positiveInt($body['id'] ?? null, 'Administrator ID');
$action = strtolower(contentString($body['action'] ?? null, 40, true, 'Security action'));
$existing = adminUserById($conn, $adminId);
if (!$existing) {
    jsonResponse(['status' => 'Failed', 'message' => 'Administrator not found.'], 404);
}

if ($adminId === (int) $admin['id']) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Use a dedicated account security flow for your own account. This screen protects your current Super Admin session.',
    ], 422);
}

if ($action === 'reset_password') {
    $password = validateAdminPassword($body['password'] ?? null, 'New password');
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        'UPDATE admin_users
         SET password_hash = ?, token_version = token_version + 1
         WHERE id = ?'
    );
    $stmt->bind_param('si', $passwordHash, $adminId);
    $stmt->execute();
    $stmt->close();
    revokeAdminUserSessions($conn, $adminId);

    recordAuditLog($conn, (int) $admin['id'], 'administrator.password_reset', 'admin_user', $adminId);
    jsonResponse([
        'status' => 'Success',
        'message' => 'Password reset successfully. Existing sessions were revoked.',
    ]);
}

if ($action === 'revoke_sessions') {
    $stmt = $conn->prepare('UPDATE admin_users SET token_version = token_version + 1 WHERE id = ?');
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $stmt->close();
    revokeAdminUserSessions($conn, $adminId);

    recordAuditLog($conn, (int) $admin['id'], 'administrator.sessions_revoked', 'admin_user', $adminId);
    jsonResponse([
        'status' => 'Success',
        'message' => 'All existing sessions for this administrator were revoked.',
    ]);
}

jsonResponse(['status' => 'Failed', 'message' => 'Security action is invalid.'], 422);
