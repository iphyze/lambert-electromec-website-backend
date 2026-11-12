<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';

requireMethod('POST');
$admin = requireContentAdmin($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody();

$projectId = positiveInt($body['project_id'] ?? null, 'Project ID');
$project = ensureProjectExists($conn, $projectId);
assertCanModifyProject($admin, $project);
$imageIds = $body['image_ids'] ?? null;
if (!is_array($imageIds)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Image order is invalid.'], 422);
}

$normalized = [];
foreach ($imageIds as $id) {
    $normalized[] = positiveInt($id, 'Image ID');
}
if (count($normalized) !== count(array_unique($normalized))) {
    jsonResponse(['status' => 'Failed', 'message' => 'Image order contains duplicate items.'], 422);
}

$stmt = $conn->prepare('SELECT id FROM project_images WHERE project_id = ? ORDER BY id ASC');
$stmt->bind_param('i', $projectId);
$stmt->execute();
$result = $stmt->get_result();
$currentIds = [];
while ($row = $result->fetch_assoc()) {
    $currentIds[] = (int) $row['id'];
}
$stmt->close();

$expected = $currentIds;
$received = $normalized;
sort($expected);
sort($received);
if ($expected !== $received) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Image order must include every image for this project exactly once.',
    ], 422);
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare('UPDATE project_images SET display_order = ? WHERE id = ? AND project_id = ?');
    foreach ($normalized as $index => $imageId) {
        $order = ($index + 1) * 10;
        $stmt->bind_param('iii', $order, $imageId, $projectId);
        $stmt->execute();
    }
    $stmt->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}

recordAuditLog($conn, (int) $admin['id'], 'project.images_reordered', 'project', $projectId, ['image_ids' => $normalized]);
jsonResponse(['status' => 'Success', 'message' => 'Project image order updated successfully.', 'data' => adminProjectImages($conn, $projectId)]);
