<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/authService.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function bearerTokenFromRequest(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }

    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Authentication required.'], 401);
    }

    return $matches[1];
}

function authenticateAdmin(mysqli $conn): array
{
    try {
        $decoded = (array) JWT::decode(
            bearerTokenFromRequest(),
            new Key(jwtSecret(), 'HS256')
        );

        if (($decoded['type'] ?? '') !== 'access') {
            throw new UnexpectedValueException('Invalid token type.');
        }
        if (($decoded['iss'] ?? '') !== jwtIssuer() || ($decoded['aud'] ?? '') !== jwtAudience()) {
            throw new UnexpectedValueException('Invalid token scope.');
        }

        $adminId = (int) ($decoded['id'] ?? 0);
        $email = strtolower(trim((string) ($decoded['email'] ?? '')));
        $tokenVersion = (int) ($decoded['token_version'] ?? 0);
        if ($adminId <= 0 || $email === '' || $tokenVersion <= 0) {
            throw new UnexpectedValueException('Incomplete token claims.');
        }

        $stmt = $conn->prepare(
            'SELECT id, first_name, last_name, email, role, status, token_version
             FROM admin_users
             WHERE id = ? AND email = ?
             LIMIT 1'
        );
        $stmt->bind_param('is', $adminId, $email);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$admin
            || strcasecmp((string) $admin['status'], 'active') !== 0
            || (int) $admin['token_version'] !== $tokenVersion) {
            jsonResponse(['status' => 'Failed', 'message' => 'Authentication session is no longer valid.'], 401);
        }

        return $admin;
    } catch (Throwable $error) {
        $code = (int) $error->getCode();
        if ($error instanceof mysqli_sql_exception || ($code >= 500 && $code <= 599)) {
            throw $error;
        }

        jsonResponse(['status' => 'Failed', 'message' => 'Invalid or expired authentication token.'], 401);
    }
}

function requireAdminRoles(mysqli $conn, array $allowedRoles): array
{
    $admin = authenticateAdmin($conn);
    if (!in_array((string) $admin['role'], $allowedRoles, true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'You are not permitted to perform this action.'], 403);
    }

    return $admin;
}

function requireContentAdmin(mysqli $conn): array
{
    return requireAdminRoles($conn, ['super_admin', 'admin', 'editor']);
}

function requireAdministrator(mysqli $conn): array
{
    return requireAdminRoles($conn, ['super_admin', 'admin']);
}

function requireSuperAdmin(mysqli $conn): array
{
    return requireAdminRoles($conn, ['super_admin']);
}
