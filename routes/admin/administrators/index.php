<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/adminUserService.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    requireSuperAdmin($conn);
    applyNoStoreHeaders();

    [$page, $limit, $offset] = paginationInput(12, 100);
    $search = trim(contentString($_GET['search'] ?? '', 190, false, 'Search'));
    $role = strtolower(trim(contentString($_GET['role'] ?? '', 30, false, 'Role')));
    $status = strtolower(trim(contentString($_GET['status'] ?? '', 30, false, 'Status')));

    if ($role !== '' && !in_array($role, ['super_admin', 'admin', 'editor'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Administrator role filter is invalid.'], 422);
    }
    if ($status !== '' && !in_array($status, ['active', 'suspended', 'disabled'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Administrator status filter is invalid.'], 422);
    }

    $like = $search === '' ? '' : '%' . $search . '%';
    $where = "(? = '' OR au.role = ?)
              AND (? = '' OR au.status = ?)
              AND (? = '' OR au.first_name LIKE ? OR au.last_name LIKE ? OR au.email LIKE ? OR CONCAT(au.first_name, ' ', au.last_name) LIKE ?)";

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM admin_users au WHERE {$where}");
    $countStmt->bind_param('sssssssss', $role, $role, $status, $status, $search, $like, $like, $like, $like);
    $countStmt->execute();
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $stmt = $conn->prepare(
        "SELECT au.id, au.first_name, au.last_name, au.email, au.role, au.status,
                au.last_login_at, au.created_at, au.updated_at
         FROM admin_users au
         WHERE {$where}
         ORDER BY
            CASE au.role WHEN 'super_admin' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END ASC,
            au.first_name ASC, au.last_name ASC, au.id ASC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('sssssssssii', $role, $role, $status, $status, $search, $like, $like, $like, $like, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = formatAdminUserRow($row);
    }
    $stmt->close();

    jsonResponse([
        'status' => 'Success',
        'data' => $items,
        'summary' => adminUserSummary($conn),
        'pagination' => paginationPayload($page, $limit, $total),
    ]);
}

if ($method !== 'POST') {
    requireMethod('GET', 'POST');
}

$admin = requireSuperAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody(50000);

$firstName = contentString($body['first_name'] ?? null, 80, true, 'First name');
$lastName = contentString($body['last_name'] ?? null, 80, true, 'Last name');
$email = normalizeAdminUserEmail($body['email'] ?? null);
$role = normalizeAdminUserRole($body['role'] ?? 'editor');
$status = normalizeAdminUserStatus($body['status'] ?? 'active');
$password = validateAdminPassword($body['password'] ?? null);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $conn->prepare(
        'INSERT INTO admin_users (first_name, last_name, email, password_hash, role, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssss', $firstName, $lastName, $email, $passwordHash, $role, $status);
    $stmt->execute();
    $adminId = (int) $conn->insert_id;
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'An administrator with that email already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, (int) $admin['id'], 'administrator.created', 'admin_user', $adminId, [
    'role' => $role,
    'status' => $status,
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Administrator created successfully.',
    'data' => adminUserById($conn, $adminId),
], 201);
