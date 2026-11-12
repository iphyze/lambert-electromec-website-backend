<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/mediaCenterService.php';

requireMethod('PATCH', 'DELETE');
$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();

$categoryId = positiveInt($_GET['id'] ?? null, 'Category ID');
$existing = mediaCategoryById($conn, $categoryId);
if (!$existing) {
    jsonResponse(['status' => 'Failed', 'message' => 'Media Center category not found.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $hardDelete = booleanFlag($_GET['hard'] ?? false);
    if ($hardDelete) {
        if ((string) $admin['role'] !== 'super_admin') {
            jsonResponse(['status' => 'Failed', 'message' => 'Only a Super Admin can permanently delete Media Center categories.'], 403);
        }

        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM media_items WHERE category_id = ?');
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $used = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($used > 0) {
            jsonResponse([
                'status' => 'Failed',
                'message' => 'This category is in use. Deactivate it instead of deleting it.',
            ], 409);
        }

        $stmt = $conn->prepare('DELETE FROM media_categories WHERE id = ?');
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $stmt->close();
        recordAuditLog($conn, (int) $admin['id'], 'media_category.deleted', 'media_category', $categoryId, [
            'slug' => $existing['slug'],
        ]);
        jsonResponse(['status' => 'Success', 'message' => 'Media Center category permanently deleted.']);
    }

    $stmt = $conn->prepare("UPDATE media_categories SET status = 'inactive' WHERE id = ?");
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $stmt->close();
    recordAuditLog($conn, (int) $admin['id'], 'media_category.deactivated', 'media_category', $categoryId);
    jsonResponse(['status' => 'Success', 'message' => 'Media Center category deactivated successfully.']);
}

$body = requestJsonBody();
$nameEn = array_key_exists('name_en', $body)
    ? contentString($body['name_en'], 160, true, 'English category name')
    : (string) $existing['name_en'];
$nameFr = array_key_exists('name_fr', $body)
    ? contentString($body['name_fr'], 160, true, 'French category name')
    : (string) $existing['name_fr'];
$requestedSlug = array_key_exists('slug', $body)
    ? contentString($body['slug'], 120, false, 'Category slug')
    : (string) $existing['slug'];
$slug = uniqueMediaCategorySlug($conn, $requestedSlug, $nameEn, $categoryId);
$status = array_key_exists('status', $body)
    ? strtolower(contentString($body['status'], 20, true, 'Status'))
    : (string) $existing['status'];
if (!in_array($status, ['active', 'inactive'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Media Center category status is invalid.'], 422);
}
$displayOrder = array_key_exists('display_order', $body)
    ? max(-100000, min(100000, (int) $body['display_order']))
    : (int) $existing['display_order'];

try {
    $stmt = $conn->prepare(
        'UPDATE media_categories SET slug = ?, name_en = ?, name_fr = ?, status = ?, display_order = ? WHERE id = ?'
    );
    $stmt->bind_param('ssssii', $slug, $nameEn, $nameFr, $status, $displayOrder, $categoryId);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A Media Center category with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, (int) $admin['id'], 'media_category.updated', 'media_category', $categoryId, [
    'slug' => $slug,
    'status' => $status,
]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Media Center category updated successfully.',
    'data' => mediaCategoryById($conn, $categoryId),
]);
