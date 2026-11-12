<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/contactService.php';

requireMethod('POST');
$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody(30000);

$submissionId = positiveInt($body['id'] ?? null, 'Contact submission ID');
$action = strtolower(contentString($body['action'] ?? null, 30, true, 'Action'));
$existing = ensureContactSubmissionExists($conn, $submissionId);
$adminId = (int) $admin['id'];

switch ($action) {
    case 'read':
        $stmt = $conn->prepare("UPDATE contact_submissions SET status = 'read' WHERE id = ?");
        $stmt->bind_param('i', $submissionId);
        $message = 'Submission marked as read.';
        break;

    case 'replied':
        $handledAt = (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s');
        $stmt = $conn->prepare(
            "UPDATE contact_submissions SET status = 'replied', handled_by = ?, handled_at = ? WHERE id = ?"
        );
        $stmt->bind_param('isi', $adminId, $handledAt, $submissionId);
        $message = 'Submission marked as replied.';
        break;

    case 'archive':
        $stmt = $conn->prepare("UPDATE contact_submissions SET status = 'archived' WHERE id = ?");
        $stmt->bind_param('i', $submissionId);
        $message = 'Submission archived successfully.';
        break;

    case 'restore':
        $restoreStatus = !empty($existing['handled_at']) ? 'replied' : 'read';
        $stmt = $conn->prepare('UPDATE contact_submissions SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $restoreStatus, $submissionId);
        $message = 'Submission restored successfully.';
        break;

    default:
        jsonResponse(['status' => 'Failed', 'message' => 'Unsupported contact action.'], 422);
}

$stmt->execute();
$stmt->close();

recordAuditLog($conn, $adminId, 'contact.' . $action, 'contact', $submissionId, [
    'previous_status' => (string) $existing['status'],
]);

$updated = ensureContactSubmissionExists($conn, $submissionId);
jsonResponse([
    'status' => 'Success',
    'message' => $message,
    'data' => formatContactSubmission($updated, contactAttachmentBySubmission($conn, $submissionId)),
]);
