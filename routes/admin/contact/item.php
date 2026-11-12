<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/contactService.php';

requireMethod('GET', 'DELETE');
$admin = requireAdministrator($conn);
applyNoStoreHeaders();
$submissionId = positiveInt($_GET['id'] ?? null, 'Contact submission ID');
$existing = ensureContactSubmissionExists($conn, $submissionId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $attachment = contactAttachmentBySubmission($conn, $submissionId);
    $deliveries = contactDeliveryHistory($conn, $submissionId, 20);
    jsonResponse([
        'status' => 'Success',
        'data' => formatContactSubmission($existing, $attachment, $deliveries),
    ]);
}

requireCsrfToken();
if ((string) $admin['role'] !== 'super_admin') {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Only a Super Admin can permanently delete contact submissions.',
    ], 403);
}

$attachment = contactAttachmentBySubmission($conn, $submissionId);
$conn->begin_transaction();
try {
    $stmt = $conn->prepare('DELETE FROM contact_submissions WHERE id = ?');
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
if ($attachment && !empty($attachment['file_path'])) {
    safelyDeleteCvUpload((string) $attachment['file_path']);
}

recordAuditLog($conn, (int) $admin['id'], 'contact.deleted', 'contact', $submissionId, [
    'subject_key' => (string) $existing['subject_key'],
]);

jsonResponse(['status' => 'Success', 'message' => 'Contact submission permanently deleted.']);
