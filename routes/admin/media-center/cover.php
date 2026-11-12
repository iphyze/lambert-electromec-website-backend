<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/mediaCenterService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('POST', 'DELETE');
$admin = requireContentAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));
$itemId = $method === 'POST'
    ? positiveInt($_POST['id'] ?? null, 'Media Center item ID')
    : positiveInt($_GET['id'] ?? null, 'Media Center item ID');
$existing = ensureMediaItemExists($conn, $itemId);
assertCanModifyMediaItem($admin, $existing);
$adminId = (int) $admin['id'];
$oldPath = (string) ($existing['cover_image_path'] ?? '');

if ($method === 'DELETE') {
    $stmt = $conn->prepare(
        'UPDATE media_items
         SET cover_image_path = NULL, cover_image_original_filename = NULL,
             cover_image_mime_type = NULL, cover_image_size = NULL,
             cover_image_width = NULL, cover_image_height = NULL, updated_by = ?
         WHERE id = ?'
    );
    $stmt->bind_param('ii', $adminId, $itemId);
    $stmt->execute();
    $stmt->close();

    safelyDeleteMediaCenterCoverImage($oldPath);
    recordAuditLog($conn, $adminId, 'media_item.cover_removed', 'media_item', $itemId, [
        'old_file' => $oldPath,
    ]);

    jsonResponse([
        'status' => 'Success',
        'message' => 'Media Center cover image removed successfully.',
        'data' => formatAdminMediaItem(ensureMediaItemExists($conn, $itemId)),
    ]);
}

$files = normalizeUploadedFiles('cover_image', 1);
$coverImage = $files[0];
$stored = storeMediaCenterCoverImage($coverImage);

try {
    $stmt = $conn->prepare(
        'UPDATE media_items
         SET cover_image_path = ?, cover_image_original_filename = ?, cover_image_mime_type = ?,
             cover_image_size = ?, cover_image_width = ?, cover_image_height = ?, updated_by = ?
         WHERE id = ?'
    );
    $stmt->bind_param(
        'sssiiiii',
        $stored['file_path'],
        $stored['original_name'],
        $stored['mime'],
        $stored['size'],
        $stored['width'],
        $stored['height'],
        $adminId,
        $itemId
    );
    $stmt->execute();
    $stmt->close();
} catch (Throwable $error) {
    safelyDeleteMediaCenterCoverImage($stored['file_path']);
    throw $error;
}

safelyDeleteMediaCenterCoverImage($oldPath);
recordAuditLog($conn, $adminId, 'media_item.cover_replaced', 'media_item', $itemId, [
    'old_file' => $oldPath,
    'new_file' => $stored['file_path'],
    'width' => $stored['width'],
    'height' => $stored['height'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => 'Media Center cover image saved successfully.',
    'data' => formatAdminMediaItem(ensureMediaItemExists($conn, $itemId)),
]);
