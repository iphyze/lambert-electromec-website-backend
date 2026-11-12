<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('PATCH', 'DELETE');
$admin = requireContentAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();
$imageId = positiveInt($_GET['id'] ?? null, 'Image ID');

$stmt = $conn->prepare(
    'SELECT pi.*, p.status AS project_status
     FROM project_images pi
     INNER JOIN projects p ON p.id = pi.project_id
     WHERE pi.id = ? LIMIT 1'
);
$stmt->bind_param('i', $imageId);
$stmt->execute();
$image = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$image) {
    jsonResponse(['status' => 'Failed', 'message' => 'Project image not found.'], 404);
}

assertCanModifyProject($admin, ['status' => $image['project_status']]);
$projectId = (int) $image['project_id'];

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if ((string) $image['project_status'] === 'published' && projectImageCount($conn, $projectId) <= 1) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'A published project must keep at least one image. Unpublish it before removing the final image.',
        ], 409);
    }

    $filePath = (string) $image['file_path'];
    $wasFeatured = (bool) $image['is_featured'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('DELETE FROM project_images WHERE id = ?');
        $stmt->bind_param('i', $imageId);
        $stmt->execute();
        $stmt->close();

        if ($wasFeatured) {
            $stmt = $conn->prepare(
                'SELECT id FROM project_images WHERE project_id = ? ORDER BY display_order ASC, id ASC LIMIT 1'
            );
            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $replacement = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($replacement) {
                $replacementId = (int) $replacement['id'];
                $stmt = $conn->prepare('UPDATE project_images SET is_featured = 1 WHERE id = ?');
                $stmt->bind_param('i', $replacementId);
                $stmt->execute();
                $stmt->close();
            }
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    safelyDeleteProjectImage($filePath);
    recordAuditLog($conn, (int) $admin['id'], 'project.image_deleted', 'project', $projectId, ['image_id' => $imageId]);
    jsonResponse(['status' => 'Success', 'message' => 'Project image removed successfully.', 'data' => adminProjectImages($conn, $projectId)]);
}

$body = requestJsonBody();
$altEn = array_key_exists('alt_text_en', $body)
    ? contentString($body['alt_text_en'], 255, false, 'English alt text')
    : (string) ($image['alt_text_en'] ?? '');
$altFr = array_key_exists('alt_text_fr', $body)
    ? contentString($body['alt_text_fr'], 255, false, 'French alt text')
    : (string) ($image['alt_text_fr'] ?? '');
$captionEn = array_key_exists('caption_en', $body)
    ? contentString($body['caption_en'], 500, false, 'English caption')
    : (string) ($image['caption_en'] ?? '');
$captionFr = array_key_exists('caption_fr', $body)
    ? contentString($body['caption_fr'], 500, false, 'French caption')
    : (string) ($image['caption_fr'] ?? '');
$displayOrder = array_key_exists('display_order', $body)
    ? max(-100000, min(100000, (int) $body['display_order']))
    : (int) $image['display_order'];
$makeFeatured = array_key_exists('is_featured', $body)
    ? booleanFlag($body['is_featured'])
    : (bool) $image['is_featured'];

if (!$makeFeatured && (bool) $image['is_featured'] && array_key_exists('is_featured', $body)) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Choose another featured image instead of clearing the current featured image.',
    ], 422);
}

$conn->begin_transaction();
try {
    if ($makeFeatured) {
        $stmt = $conn->prepare('UPDATE project_images SET is_featured = 0 WHERE project_id = ?');
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $stmt->close();
    }

    $featuredFlag = $makeFeatured ? 1 : 0;
    $stmt = $conn->prepare(
        "UPDATE project_images SET
            alt_text_en = NULLIF(?, ''), alt_text_fr = NULLIF(?, ''),
            caption_en = NULLIF(?, ''), caption_fr = NULLIF(?, ''),
            is_featured = ?, display_order = ?
         WHERE id = ?"
    );
    $stmt->bind_param('ssssiii', $altEn, $altFr, $captionEn, $captionFr, $featuredFlag, $displayOrder, $imageId);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}

recordAuditLog($conn, (int) $admin['id'], 'project.image_updated', 'project', $projectId, ['image_id' => $imageId]);
jsonResponse(['status' => 'Success', 'message' => 'Project image updated successfully.', 'data' => adminProjectImages($conn, $projectId)]);
