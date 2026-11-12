<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/facilityProjectService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('POST', 'DELETE');
$admin = requireContentAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();

$id = positiveInt($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null), 'Facility project ID');
$existing = ensureFacilityProjectExists($conn, $id);
assertCanModifyFacilityProject($admin, $existing);
$adminId = (int) $admin['id'];
$oldPath = (string) ($existing['image_path'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if ((string) ($existing['status'] ?? '') === 'published') {
        jsonResponse(['status' => 'Failed', 'message' => 'Unpublish this Facility Management project before removing its image.'], 409);
    }
    $stmt = $conn->prepare('UPDATE facility_projects SET image_path = NULL, image_original_filename = NULL, image_mime_type = NULL, image_size = NULL, updated_by = ? WHERE id = ?');
    $stmt->bind_param('ii', $adminId, $id);
    $stmt->execute();
    $stmt->close();
    safelyDeleteFacilityProjectImage($oldPath);
    recordAuditLog($conn, $adminId, 'facility_project.image_removed', 'facility_project', $id);
    jsonResponse(['status' => 'Success', 'message' => 'Facility project image removed.', 'data' => formatAdminFacilityProject(ensureFacilityProjectExists($conn, $id))]);
}

$file = $_FILES['image'] ?? null;
if (!is_array($file)) jsonResponse(['status' => 'Failed', 'message' => 'Choose an image to upload.'], 422);
facilityProjectImageSpecification($file);
$stored = storeFacilityProjectImage($file);

try {
    $stmt = $conn->prepare('UPDATE facility_projects SET image_path = ?, image_original_filename = ?, image_mime_type = ?, image_size = ?, updated_by = ? WHERE id = ?');
    executePrepared($stmt, 'sssiii', [$stored['file_path'], $stored['original_name'], $stored['mime'], $stored['size'], $adminId, $id]);
    $stmt->close();
} catch (Throwable $error) {
    safelyDeleteFacilityProjectImage($stored['file_path']);
    throw $error;
}

safelyDeleteFacilityProjectImage($oldPath);
recordAuditLog($conn, $adminId, 'facility_project.image_replaced', 'facility_project', $id);
jsonResponse(['status' => 'Success', 'message' => 'Facility project image updated.', 'data' => formatAdminFacilityProject(ensureFacilityProjectExists($conn, $id))]);
