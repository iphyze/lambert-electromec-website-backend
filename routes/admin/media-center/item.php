<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/mediaCenterService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('GET', 'PATCH', 'DELETE');
$admin = requireContentAdmin($conn);
applyNoStoreHeaders();
$itemId = positiveInt($_GET['id'] ?? null, 'Media Center item ID');
$existing = ensureMediaItemExists($conn, $itemId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse(['status' => 'Success', 'data' => formatAdminMediaItem($existing)]);
}

requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!in_array((string) $admin['role'], ['super_admin', 'admin'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Only an Admin can archive Media Center items.'], 403);
    }

    $hardDelete = booleanFlag($_GET['hard'] ?? false);
    if (!$hardDelete) {
        $adminId = (int) $admin['id'];
        $stmt = $conn->prepare("UPDATE media_items SET status = 'archived', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $itemId);
        $stmt->execute();
        $stmt->close();
        recordAuditLog($conn, $adminId, 'media_item.archived', 'media_item', $itemId, [
            'title_en' => $existing['title_en'],
        ]);
        jsonResponse(['status' => 'Success', 'message' => 'Media Center item archived successfully.']);
    }

    if ((string) $admin['role'] !== 'super_admin') {
        jsonResponse(['status' => 'Failed', 'message' => 'Only a Super Admin can permanently delete Media Center items.'], 403);
    }

    $filePath = (string) $existing['file_path'];
    $coverImagePath = (string) ($existing['cover_image_path'] ?? '');
    $stmt = $conn->prepare('DELETE FROM media_items WHERE id = ?');
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $stmt->close();
    safelyDeleteMediaCenterDocument($filePath);
    safelyDeleteMediaCenterCoverImage($coverImagePath);
    recordAuditLog($conn, (int) $admin['id'], 'media_item.deleted', 'media_item', $itemId, [
        'title_en' => $existing['title_en'],
    ]);
    jsonResponse(['status' => 'Success', 'message' => 'Media Center item permanently deleted.']);
}

assertCanModifyMediaItem($admin, $existing);
$body = requestJsonBody(100000);
$categoryId = array_key_exists('category_id', $body)
    ? positiveInt($body['category_id'], 'Category ID')
    : (int) $existing['category_id'];
if (!mediaCategoryById($conn, $categoryId, true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Select an active Media Center category.'], 422);
}

$titleEn = array_key_exists('title_en', $body)
    ? contentString($body['title_en'], 255, true, 'English title')
    : (string) $existing['title_en'];
$titleFr = array_key_exists('title_fr', $body)
    ? contentString($body['title_fr'], 255, false, 'French title')
    : (string) ($existing['title_fr'] ?? '');
$requestedSlug = array_key_exists('slug', $body)
    ? contentString($body['slug'], 220, false, 'Slug')
    : (string) $existing['slug'];
if ((string) $existing['status'] === 'published' && $requestedSlug !== (string) $existing['slug']) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Unpublish the Media Center item before changing its URL slug.',
    ], 409);
}
$slug = uniqueMediaItemSlug($conn, $requestedSlug, $titleEn, $itemId);
$reference = array_key_exists('reference', $body)
    ? contentString($body['reference'], 120, false, 'Reference')
    : (string) ($existing['reference'] ?? '');
$descriptionEn = array_key_exists('description_en', $body)
    ? contentString($body['description_en'], 10000, false, 'English description')
    : (string) ($existing['description_en'] ?? '');
$descriptionFr = array_key_exists('description_fr', $body)
    ? contentString($body['description_fr'], 10000, false, 'French description')
    : (string) ($existing['description_fr'] ?? '');
$featured = array_key_exists('featured', $body)
    ? (booleanFlag($body['featured']) ? 1 : 0)
    : (int) $existing['featured'];
$displayOrder = array_key_exists('display_order', $body)
    ? max(-100000, min(100000, (int) $body['display_order']))
    : (int) $existing['display_order'];
$adminId = (int) $admin['id'];

try {
    $stmt = $conn->prepare(
        "UPDATE media_items SET
            category_id = ?, slug = ?, reference = NULLIF(?, ''), title_en = ?, title_fr = ?,
            description_en = NULLIF(?, ''), description_fr = NULLIF(?, ''),
            featured = ?, display_order = ?, updated_by = ?
         WHERE id = ?"
    );
    $values = [
        $categoryId, $slug, $reference, $titleEn, $titleFr, $descriptionEn, $descriptionFr,
        $featured, $displayOrder, $adminId, $itemId,
    ];
    executePrepared($stmt, 'i' . str_repeat('s', 6) . 'iiii', $values);
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A Media Center item with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, $adminId, 'media_item.updated', 'media_item', $itemId, [
    'title_en' => $titleEn,
    'slug' => $slug,
    'category_id' => $categoryId,
]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Media Center item updated successfully.',
    'data' => formatAdminMediaItem(ensureMediaItemExists($conn, $itemId)),
]);
