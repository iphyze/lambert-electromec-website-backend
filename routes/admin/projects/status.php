<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';

requireMethod('POST');
$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody();

$projectId = positiveInt($body['id'] ?? null, 'Project ID');
$action = strtolower(contentString($body['action'] ?? null, 30, true, 'Action'));
$existing = ensureProjectExists($conn, $projectId);
$adminId = (int) $admin['id'];

switch ($action) {
    case 'publish':
        $issues = projectPublishReadiness($conn, $existing);
        if ($issues !== []) {
            jsonResponse([
                'status' => 'Failed',
                'message' => 'Project is not ready to publish.',
                'errors' => $issues,
            ], 409);
        }
        $publishedAt = array_key_exists('published_at', $body)
            ? normalizeDateTime($body['published_at'], 'Publish date')
            : ($existing['published_at'] ?: (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s'));
        if ($publishedAt === null) {
            $publishedAt = (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s');
        }
        $stmt = $conn->prepare("UPDATE projects SET status = 'published', published_at = ?, updated_by = ? WHERE id = ?");
        $stmt->bind_param('sii', $publishedAt, $adminId, $projectId);
        $isFuturePublication = new DateTimeImmutable($publishedAt, appTimeZone()) > new DateTimeImmutable('now', appTimeZone());
        $message = $isFuturePublication ? 'Project scheduled for publication.' : 'Project published successfully.';
        break;

    case 'unpublish':
        $stmt = $conn->prepare("UPDATE projects SET status = 'draft', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $projectId);
        $message = 'Project moved back to draft.';
        break;

    case 'archive':
        $stmt = $conn->prepare("UPDATE projects SET status = 'archived', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $projectId);
        $message = 'Project archived successfully.';
        break;

    case 'restore':
        assertFeaturedProjectCapacity($conn, (bool) ($existing['featured'] ?? false), $projectId);
        $stmt = $conn->prepare("UPDATE projects SET status = 'draft', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $projectId);
        $message = 'Project restored as a draft.';
        break;

    default:
        jsonResponse(['status' => 'Failed', 'message' => 'Unsupported project action.'], 422);
}

$stmt->execute();
$stmt->close();
recordAuditLog($conn, $adminId, 'project.' . $action, 'project', $projectId, [
    'previous_status' => $existing['status'],
]);

$payload = formatAdminProjectRow(ensureProjectExists($conn, $projectId));
$payload['images'] = adminProjectImages($conn, $projectId);
jsonResponse(['status' => 'Success', 'message' => $message, 'data' => $payload]);
