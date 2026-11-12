<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('POST', 'DELETE');
$admin = requireContentAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $projectId = positiveInt($_GET['project_id'] ?? null, 'Project ID');
    $project = ensureProjectExists($conn, $projectId);
    assertCanModifyProject($admin, $project);
    $existing = adminProjectHomeImage($conn, $projectId);

    if (!$existing) {
        jsonResponse(['status' => 'Success', 'message' => 'Homepage image is already empty.', 'data' => null]);
    }

    $stmt = $conn->prepare('DELETE FROM project_home_images WHERE project_id = ?');
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $stmt->close();

    safelyDeleteProjectHomeImage($conn, (string) $existing['file_path']);
    recordAuditLog($conn, (int) $admin['id'], 'project.home_image_deleted', 'project', $projectId, [
        'image_id' => (int) $existing['id'],
    ]);

    jsonResponse(['status' => 'Success', 'message' => 'Homepage featured image removed.', 'data' => null]);
}

$projectId = positiveInt($_POST['project_id'] ?? null, 'Project ID');
$project = ensureProjectExists($conn, $projectId);
assertCanModifyProject($admin, $project);
$files = normalizeUploadedFiles('file', 1);
$stored = storeProjectHomeImage($files[0]);
$existing = adminProjectHomeImage($conn, $projectId);
$oldFilePath = $existing['file_path'] ?? null;
$altEn = contentString($project['title_en'] ?? '', 255, false, 'English title');
$altFr = contentString($project['title_fr'] ?? '', 255, false, 'French title');

try {
    $stmt = $conn->prepare(
        "INSERT INTO project_home_images
            (project_id, file_path, original_filename, mime_type, file_size, alt_text_en, alt_text_fr)
         VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''))
         ON DUPLICATE KEY UPDATE
            file_path = VALUES(file_path),
            original_filename = VALUES(original_filename),
            mime_type = VALUES(mime_type),
            file_size = VALUES(file_size),
            alt_text_en = VALUES(alt_text_en),
            alt_text_fr = VALUES(alt_text_fr)"
    );
    $values = [
        $projectId,
        $stored['file_path'],
        $stored['original_name'],
        $stored['mime'],
        $stored['size'],
        $altEn,
        $altFr,
    ];
    executePrepared($stmt, 'isssiss', $values);
    $stmt->close();
} catch (Throwable $error) {
    safelyDeleteProjectImage($stored['file_path']);
    throw $error;
}

if ($oldFilePath && $oldFilePath !== $stored['file_path']) {
    safelyDeleteProjectHomeImage($conn, (string) $oldFilePath);
}

$image = adminProjectHomeImage($conn, $projectId);
recordAuditLog($conn, (int) $admin['id'], 'project.home_image_updated', 'project', $projectId, [
    'image_id' => (int) ($image['id'] ?? 0),
    'original_filename' => $stored['original_name'],
]);

jsonResponse([
    'status' => 'Success',
    'message' => $existing ? 'Homepage featured image replaced.' : 'Homepage featured image uploaded.',
    'data' => $image,
], $existing ? 200 : 201);
