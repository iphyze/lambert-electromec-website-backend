<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/mediaCenterService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('POST');
$admin = requireContentAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();

$itemId = positiveInt($_POST['id'] ?? null, 'Media Center item ID');
$existing = ensureMediaItemExists($conn, $itemId);
assertCanModifyMediaItem($admin, $existing);
$files = normalizeUploadedFiles('document', 1);
$document = $files[0];
mediaCenterDocumentSpecification($document);
$stored = storeMediaCenterDocument($document);
$adminId = (int) $admin['id'];
$oldPath = (string) $existing['file_path'];

try {
    $stmt = $conn->prepare(
        'UPDATE media_items
         SET file_path = ?, original_filename = ?, mime_type = ?, file_size = ?, updated_by = ?
         WHERE id = ?'
    );
    $stmt->bind_param(
        'sssiii',
        $stored['file_path'],
        $stored['original_name'],
        $stored['mime'],
        $stored['size'],
        $adminId,
        $itemId
    );
    $stmt->execute();
    $stmt->close();
} catch (Throwable $error) {
    safelyDeleteMediaCenterDocument($stored['file_path']);
    throw $error;
}

safelyDeleteMediaCenterDocument($oldPath);
recordAuditLog($conn, $adminId, 'media_item.file_replaced', 'media_item', $itemId, [
    'old_file' => $oldPath,
    'new_file' => $stored['file_path'],
]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Media Center document replaced successfully.',
    'data' => formatAdminMediaItem(ensureMediaItemExists($conn, $itemId)),
]);
