<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/facilityProjectService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('GET', 'PATCH', 'DELETE');
$admin = requireContentAdmin($conn);
applyNoStoreHeaders();
$id = positiveInt($_GET['id'] ?? null, 'Facility project ID');
$existing = ensureFacilityProjectExists($conn, $id);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse(['status' => 'Success', 'data' => formatAdminFacilityProject($existing)]);
}

requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!in_array((string) $admin['role'], ['super_admin', 'admin'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Only an Admin can archive Facility Management projects.'], 403);
    }

    $hardDelete = booleanFlag($_GET['hard'] ?? false);
    if (!$hardDelete) {
        $stmt = $conn->prepare("UPDATE facility_projects SET status = 'archived', updated_by = ? WHERE id = ?");
        $adminId = (int) $admin['id'];
        $stmt->bind_param('ii', $adminId, $id);
        $stmt->execute();
        $stmt->close();
        recordAuditLog($conn, $adminId, 'facility_project.archived', 'facility_project', $id, ['title_en' => $existing['title_en']]);
        jsonResponse(['status' => 'Success', 'message' => 'Facility Management project archived successfully.']);
    }

    if ((string) $admin['role'] !== 'super_admin') {
        jsonResponse(['status' => 'Failed', 'message' => 'Only a Super Admin can permanently delete Facility Management projects.'], 403);
    }

    $imagePath = (string) ($existing['image_path'] ?? '');
    $stmt = $conn->prepare('DELETE FROM facility_projects WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    safelyDeleteFacilityProjectImage($imagePath);
    recordAuditLog($conn, (int) $admin['id'], 'facility_project.deleted', 'facility_project', $id, ['title_en' => $existing['title_en']]);
    jsonResponse(['status' => 'Success', 'message' => 'Facility Management project permanently deleted.']);
}

assertCanModifyFacilityProject($admin, $existing);
$body = requestJsonBody(100000);
$titleEn = array_key_exists('title_en', $body) ? contentString($body['title_en'], 255, true, 'English title') : (string) $existing['title_en'];
$titleFr = array_key_exists('title_fr', $body) ? contentString($body['title_fr'], 255, false, 'French title') : (string) ($existing['title_fr'] ?? '');
$requestedSlug = array_key_exists('slug', $body) ? contentString($body['slug'], 220, false, 'Slug') : (string) $existing['slug'];
if ((string) $existing['status'] === 'published') $requestedSlug = (string) $existing['slug'];
$slug = uniqueFacilityProjectSlug($conn, $requestedSlug, $titleEn, $id);
$descriptionEn = array_key_exists('description_en', $body) ? contentString($body['description_en'], 30000, false, 'English description') : (string) ($existing['description_en'] ?? '');
$descriptionFr = array_key_exists('description_fr', $body) ? contentString($body['description_fr'], 30000, false, 'French description') : (string) ($existing['description_fr'] ?? '');
$workDoneEn = array_key_exists('work_done_en', $body) ? contentString($body['work_done_en'], 12000, false, 'English work done') : (string) ($existing['work_done_en'] ?? '');
$workDoneFr = array_key_exists('work_done_fr', $body) ? contentString($body['work_done_fr'], 12000, false, 'French work done') : (string) ($existing['work_done_fr'] ?? '');
$displayOrder = array_key_exists('display_order', $body) ? max(-100000, min(100000, (int) $body['display_order'])) : (int) $existing['display_order'];
$adminId = (int) $admin['id'];

try {
    $stmt = $conn->prepare(
        "UPDATE facility_projects SET slug = ?, title_en = ?, title_fr = ?,
            description_en = NULLIF(?, ''), description_fr = NULLIF(?, ''),
            work_done_en = NULLIF(?, ''), work_done_fr = NULLIF(?, ''),
            display_order = ?, updated_by = ? WHERE id = ?"
    );
    executePrepared($stmt, 'sssssssiii', [$slug, $titleEn, $titleFr, $descriptionEn, $descriptionFr, $workDoneEn, $workDoneFr, $displayOrder, $adminId, $id]);
    $stmt->close();
} catch (Throwable $error) {
    if ($error instanceof mysqli_sql_exception && (int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A Facility Management project with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, $adminId, 'facility_project.updated', 'facility_project', $id, ['title_en' => $titleEn, 'slug' => $slug]);
jsonResponse(['status' => 'Success', 'message' => 'Facility Management project updated successfully.', 'data' => formatAdminFacilityProject(ensureFacilityProjectExists($conn, $id))]);
