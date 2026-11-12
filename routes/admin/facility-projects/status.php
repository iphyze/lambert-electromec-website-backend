<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/facilityProjectService.php';

requireMethod('POST');
$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody();

$id = positiveInt($body['id'] ?? null, 'Facility project ID');
$action = strtolower(contentString($body['action'] ?? null, 30, true, 'Action'));
$existing = ensureFacilityProjectExists($conn, $id);
$adminId = (int) $admin['id'];

switch ($action) {
    case 'publish':
        $issues = facilityProjectPublishReadiness($existing);
        if ($issues !== []) {
            jsonResponse(['status' => 'Failed', 'message' => 'Facility Management project is not ready to publish.', 'errors' => $issues], 409);
        }
        $publishedAt = array_key_exists('published_at', $body)
            ? normalizeDateTime($body['published_at'], 'Publish date')
            : ($existing['published_at'] ?: (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s'));
        if ($publishedAt === null) $publishedAt = (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE facility_projects SET status = 'published', published_at = ?, updated_by = ? WHERE id = ?");
        $stmt->bind_param('sii', $publishedAt, $adminId, $id);
        $message = 'Facility Management project published successfully.';
        break;
    case 'unpublish':
        $stmt = $conn->prepare("UPDATE facility_projects SET status = 'draft', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $id);
        $message = 'Facility Management project moved back to draft.';
        break;
    case 'archive':
        $stmt = $conn->prepare("UPDATE facility_projects SET status = 'archived', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $id);
        $message = 'Facility Management project archived successfully.';
        break;
    case 'restore':
        $stmt = $conn->prepare("UPDATE facility_projects SET status = 'draft', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $id);
        $message = 'Facility Management project restored as a draft.';
        break;
    default:
        jsonResponse(['status' => 'Failed', 'message' => 'Unsupported Facility Management project action.'], 422);
}

$stmt->execute();
$stmt->close();
recordAuditLog($conn, $adminId, 'facility_project.' . $action, 'facility_project', $id, ['previous_status' => $existing['status']]);
jsonResponse(['status' => 'Success', 'message' => $message, 'data' => formatAdminFacilityProject(ensureFacilityProjectExists($conn, $id))]);
