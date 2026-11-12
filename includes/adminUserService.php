<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contentService.php';

function adminUserById(mysqli $conn, int $adminId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, first_name, last_name, email, role, status, last_login_at, created_at, updated_at
         FROM admin_users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? formatAdminUserRow($row) : null;
}

function formatAdminUserRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'role' => (string) ($row['role'] ?? 'editor'),
        'status' => (string) ($row['status'] ?? 'disabled'),
        'last_login_at' => $row['last_login_at'] ?: null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function normalizeAdminUserEmail(mixed $value, string $field = 'Email'): string
{
    $email = strtolower(contentString($value, 190, true, $field));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Enter a valid email address.'], 422);
    }
    return $email;
}

function normalizeAdminUserRole(mixed $value): string
{
    $role = strtolower(contentString($value, 30, true, 'Role'));
    if (!in_array($role, ['super_admin', 'admin', 'editor'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Administrator role is invalid.'], 422);
    }
    return $role;
}

function normalizeAdminUserStatus(mixed $value): string
{
    $status = strtolower(contentString($value, 30, true, 'Status'));
    if (!in_array($status, ['active', 'suspended', 'disabled'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Administrator status is invalid.'], 422);
    }
    return $status;
}

function validateAdminPassword(mixed $value, string $field = 'Password'): string
{
    if (!is_string($value)) {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' is required.'], 422);
    }

    $password = $value;
    $length = strlen($password);
    if ($length < 12 || $length > 255) {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' must be between 12 and 255 characters.'], 422);
    }

    return $password;
}

function activeSuperAdminCount(mysqli $conn, ?int $excludeId = null): int
{
    if ($excludeId !== null) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM admin_users
             WHERE role = 'super_admin' AND status = 'active' AND id <> ?"
        );
        $stmt->bind_param('i', $excludeId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $total;
    }

    $row = $conn->query(
        "SELECT COUNT(*) AS total FROM admin_users WHERE role = 'super_admin' AND status = 'active'"
    )->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}

function adminUserSummary(mysqli $conn): array
{
    $result = $conn->query('SELECT role, status, COUNT(*) AS total FROM admin_users GROUP BY role, status');
    $summary = [
        'total' => 0,
        'active' => 0,
        'super_admins' => 0,
        'restricted' => 0,
    ];

    while ($row = $result->fetch_assoc()) {
        $count = (int) $row['total'];
        $summary['total'] += $count;
        if ((string) $row['status'] === 'active') {
            $summary['active'] += $count;
        } else {
            $summary['restricted'] += $count;
        }
        if ((string) $row['role'] === 'super_admin') {
            $summary['super_admins'] += $count;
        }
    }

    return $summary;
}

function revokeAdminUserSessions(mysqli $conn, int $adminId): void
{
    $stmt = $conn->prepare(
        'UPDATE auth_refresh_tokens
         SET revoked_at = COALESCE(revoked_at, NOW())
         WHERE admin_user_id = ? AND revoked_at IS NULL'
    );
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $stmt->close();
}
