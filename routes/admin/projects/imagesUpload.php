<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('POST');
$admin = requireContentAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();

$projectId = positiveInt($_POST['project_id'] ?? null, 'Project ID');
$project = ensureProjectExists($conn, $projectId);
assertCanModifyProject($admin, $project);
$files = normalizeUploadedFiles('files', projectImageMaxFilesPerRequest());

foreach ($files as $file) {
    projectImageSpecification($file);
}

$currentCount = projectImageCount($conn, $projectId);
$maxTotal = projectImageMaxTotal();
if ($currentCount + count($files) > $maxTotal) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'A project can contain a maximum of ' . $maxTotal . ' images.',
    ], 422);
}

$stmt = $conn->prepare('SELECT COALESCE(MAX(display_order), 0) AS max_order FROM project_images WHERE project_id = ?');
$stmt->bind_param('i', $projectId);
$stmt->execute();
$nextOrder = (int) ($stmt->get_result()->fetch_assoc()['max_order'] ?? 0);
$stmt->close();

$storedFiles = [];
$insertedIds = [];
$conn->begin_transaction();
try {
    foreach ($files as $index => $file) {
        $stored = storeProjectImage($file);
        $storedFiles[] = $stored['file_path'];
        $nextOrder += 10;
        $isFeatured = ($currentCount === 0 && $index === 0) ? 1 : 0;
        $altEn = contentString($project['title_en'] ?? '', 255, false, 'English title');
        $altFr = contentString($project['title_fr'] ?? '', 255, false, 'French title');

        $stmt = $conn->prepare(
            "INSERT INTO project_images
                (project_id, file_path, original_filename, mime_type, file_size,
                 alt_text_en, alt_text_fr, is_featured, display_order)
             VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?)"
        );
        $values = [
            $projectId,
            $stored['file_path'],
            $stored['original_name'],
            $stored['mime'],
            $stored['size'],
            $altEn,
            $altFr,
            $isFeatured,
            $nextOrder,
        ];
        executePrepared($stmt, 'isssissii', $values);
        $insertedIds[] = (int) $conn->insert_id;
        $stmt->close();
    }
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    foreach ($storedFiles as $filePath) {
        safelyDeleteProjectImage($filePath);
    }
    throw $error;
}

recordAuditLog($conn, (int) $admin['id'], 'project.images_uploaded', 'project', $projectId, [
    'image_ids' => $insertedIds,
    'count' => count($insertedIds),
]);
jsonResponse([
    'status' => 'Success',
    'message' => count($insertedIds) === 1 ? 'Project image uploaded successfully.' : 'Project images uploaded successfully.',
    'data' => adminProjectImages($conn, $projectId),
], 201);
