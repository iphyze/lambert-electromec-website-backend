<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';

requireMethod('PATCH', 'DELETE');
$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();

$divisionId = positiveInt($_GET['id'] ?? null, 'Division ID');
$existing = divisionById($conn, $divisionId);
if (!$existing) {
    jsonResponse(['status' => 'Failed', 'message' => 'Division not found.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $hardDelete = booleanFlag($_GET['hard'] ?? false);
    if ($hardDelete) {
        if ((string) $admin['role'] !== 'super_admin') {
            jsonResponse(['status' => 'Failed', 'message' => 'Only a Super Admin can permanently delete divisions.'], 403);
        }

        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM projects WHERE division_id = ?');
        $stmt->bind_param('i', $divisionId);
        $stmt->execute();
        $used = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($used > 0) {
            jsonResponse([
                'status' => 'Failed',
                'message' => 'This division is in use. Deactivate it instead of deleting it.',
            ], 409);
        }

        $stmt = $conn->prepare('DELETE FROM divisions WHERE id = ?');
        $stmt->bind_param('i', $divisionId);
        $stmt->execute();
        $stmt->close();
        recordAuditLog($conn, (int) $admin['id'], 'division.deleted', 'division', $divisionId, [
            'division_key' => $existing['division_key'],
        ]);
        jsonResponse(['status' => 'Success', 'message' => 'Division permanently deleted.']);
    }

    $stmt = $conn->prepare("UPDATE divisions SET status = 'inactive' WHERE id = ?");
    $stmt->bind_param('i', $divisionId);
    $stmt->execute();
    $stmt->close();
    recordAuditLog($conn, (int) $admin['id'], 'division.deactivated', 'division', $divisionId);
    jsonResponse(['status' => 'Success', 'message' => 'Division deactivated successfully.']);
}

$body = requestJsonBody();
if (array_key_exists('division_key', $body)
    && trim((string) $body['division_key']) !== (string) $existing['division_key']) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Division keys are stable identifiers and cannot be changed after creation.',
    ], 422);
}

$nameEn = array_key_exists('name_en', $body)
    ? contentString($body['name_en'], 160, true, 'English division name')
    : (string) $existing['name_en'];
$nameFr = array_key_exists('name_fr', $body)
    ? contentString($body['name_fr'], 160, true, 'French division name')
    : (string) $existing['name_fr'];
$requestedSlug = array_key_exists('slug', $body)
    ? contentString($body['slug'], 120, false, 'Division slug')
    : (string) $existing['slug'];
$slug = uniqueDivisionSlug($conn, $requestedSlug, $nameEn, $divisionId);
$status = array_key_exists('status', $body)
    ? strtolower(contentString($body['status'], 20, true, 'Status'))
    : (string) $existing['status'];
if (!in_array($status, ['active', 'inactive'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Division status is invalid.'], 422);
}
$displayOrder = array_key_exists('display_order', $body)
    ? max(-100000, min(100000, (int) $body['display_order']))
    : (int) $existing['display_order'];

try {
    $stmt = $conn->prepare(
        'UPDATE divisions SET slug = ?, name_en = ?, name_fr = ?, status = ?, display_order = ? WHERE id = ?'
    );
    $stmt->bind_param('ssssii', $slug, $nameEn, $nameFr, $status, $displayOrder, $divisionId);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A division with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, (int) $admin['id'], 'division.updated', 'division', $divisionId, [
    'division_key' => $existing['division_key'],
    'slug' => $slug,
    'status' => $status,
]);
jsonResponse(['status' => 'Success', 'message' => 'Division updated successfully.', 'data' => divisionById($conn, $divisionId)]);
