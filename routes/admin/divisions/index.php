<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    requireContentAdmin($conn);
    applyNoStoreHeaders();

    $result = $conn->query(
        'SELECT d.id, d.division_key, d.slug, d.name_en, d.name_fr, d.status, d.display_order,
                d.created_at, d.updated_at,
                (SELECT COUNT(*) FROM projects p WHERE p.division_id = d.id) AS projects_count,
                (SELECT COUNT(*) FROM projects p WHERE p.division_id = d.id AND p.status = \'published\') AS published_projects_count
         FROM divisions d
         ORDER BY d.display_order ASC, d.id ASC'
    );

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['display_order'] = (int) $row['display_order'];
        $row['projects_count'] = (int) $row['projects_count'];
        $row['published_projects_count'] = (int) $row['published_projects_count'];
        $items[] = $row;
    }
    jsonResponse(['status' => 'Success', 'data' => $items]);
}

if ($method !== 'POST') {
    requireMethod('GET', 'POST');
}

$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody();

$nameEn = contentString($body['name_en'] ?? null, 160, true, 'English division name');
$nameFr = contentString($body['name_fr'] ?? null, 160, true, 'French division name');
$requestedSlug = contentString($body['slug'] ?? '', 120, false, 'Division slug');
$slug = uniqueDivisionSlug($conn, $requestedSlug, $nameEn);
$requestedKey = contentString($body['division_key'] ?? '', 80, false, 'Division key');
$divisionKey = uniqueDivisionKey($conn, $requestedKey, $nameEn);
$status = strtolower(contentString($body['status'] ?? 'active', 20, false, 'Status'));
if (!in_array($status, ['active', 'inactive'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Division status is invalid.'], 422);
}
$displayOrder = max(-100000, min(100000, (int) ($body['display_order'] ?? 0)));

try {
    $stmt = $conn->prepare(
        'INSERT INTO divisions (division_key, slug, name_en, name_fr, status, display_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssi', $divisionKey, $slug, $nameEn, $nameFr, $status, $displayOrder);
    $stmt->execute();
    $divisionId = (int) $conn->insert_id;
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A division with that key or slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, (int) $admin['id'], 'division.created', 'division', $divisionId, [
    'division_key' => $divisionKey,
    'slug' => $slug,
]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Division created successfully.',
    'data' => divisionById($conn, $divisionId),
], 201);
