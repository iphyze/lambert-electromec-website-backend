<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authService.php';

use Respect\Validation\Validator as v;

try {
    requireMethod('POST');
    requireTrustedRequestOrigin();
    applyNoStoreHeaders();

    $data = requestJsonBody(65536);
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');

    if (!v::email()->validate($email) || $password === '' || strlen($password) > 4096) {
        throw new RuntimeException('Invalid email or password.', 401);
    }

    assertLoginNotRateLimited($conn, $email);

    $stmt = $conn->prepare(
        'SELECT id, first_name, last_name, email, password_hash, role, status, token_version
         FROM admin_users
         WHERE email = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $valid = $admin
        && strcasecmp((string) $admin['status'], 'active') === 0
        && password_verify($password, (string) $admin['password_hash']);

    if (!$valid) {
        recordLoginAttempt($conn, $email, false);
        throw new RuntimeException('Invalid email or password.', 401);
    }

    if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $adminId = (int) $admin['id'];
        $rehash = $conn->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $rehash->bind_param('si', $newHash, $adminId);
        $rehash->execute();
        $rehash->close();
    }

    recordLoginAttempt($conn, $email, true);

    $adminId = (int) $admin['id'];
    $lastLogin = $conn->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?');
    $lastLogin->bind_param('i', $adminId);
    $lastLogin->execute();
    $lastLogin->close();

    issueRefreshSession($conn, $adminId);
    $csrfToken = issueCsrfCookie();
    $access = issueAccessToken($admin);
    recordAuditLog($conn, (int) $admin['id'], 'auth.login');

    jsonResponse([
        'status' => 'Success',
        'message' => 'Login successful.',
        'data' => array_merge(publicAdminPayload($admin), [
            'token' => $access['token'],
            'token_expires_at' => $access['expires_at'],
            'csrf_token' => $csrfToken,
        ]),
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    if ($status >= 500) {
        error_log('Lambert login error: ' . $error->getMessage());
    }

    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to sign in at this time.' : $error->getMessage(),
    ], $status);
}
