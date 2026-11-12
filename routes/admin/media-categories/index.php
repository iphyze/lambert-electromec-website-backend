<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/mediaCenterService.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    requireContentAdmin($conn);
    applyNoStoreHeaders();

    $result = $conn->query(
        'SELECT c.id, c.slug, c.name_en, c.name_fr, c.status, c.display_order,
                c.created_at, c.updated_at,
                (SELECT COUNT(*) FROM media_items m WHERE m.category_id = c.id) AS items_count,
                (SELECT COUNT(*) FROM media_items m WHERE m.category_id = c.id AND m.status = \'published\') AS published_items_count
         FROM media_categories c
         ORDER BY c.display_order ASC, c.id ASC'
    );

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['display_order'] = (int) $row['display_order'];
        $row['items_count'] = (int) $row['items_count'];
        $row['published_items_count'] = (int) $row['published_items_count'];
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

$nameEn = contentString($body['name_en'] ?? null, 160, true, 'English category name');
$nameFr = contentString($body['name_fr'] ?? null, 160, true, 'French category name');
$requestedSlug = contentString($body['slug'] ?? '', 120, false, 'Category slug');
$slug = uniqueMediaCategorySlug($conn, $requestedSlug, $nameEn);
$status = strtolower(contentString($body['status'] ?? 'active', 20, false, 'Status'));
if (!in_array($status, ['active', 'inactive'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Media Center category status is invalid.'], 422);
}
$displayOrder = max(-100000, min(100000, (int) ($body['display_order'] ?? 0)));

try {
    $stmt = $conn->prepare(
        'INSERT INTO media_categories (slug, name_en, name_fr, status, display_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssi', $slug, $nameEn, $nameFr, $status, $displayOrder);
    $stmt->execute();
    $categoryId = (int) $conn->insert_id;
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A Media Center category with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, (int) $admin['id'], 'media_category.created', 'media_category', $categoryId, [
    'slug' => $slug,
    'name_en' => $nameEn,
]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Media Center category created successfully.',
    'data' => mediaCategoryById($conn, $categoryId),
], 201);
