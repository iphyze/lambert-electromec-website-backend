<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/contactService.php';
require_once __DIR__ . '/../../../includes/emailService.php';

requireMethod('POST');
$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody(30000);
$submissionId = positiveInt($body['id'] ?? null, 'Contact submission ID');
ensureContactSubmissionExists($conn, $submissionId);

$result = deliverContactSubmissionEmail($conn, $submissionId, (int) $admin['id']);
recordAuditLog($conn, (int) $admin['id'], 'contact.email.retry', 'contact', $submissionId, [
    'result' => $result['status'] ?? 'unknown',
]);

$updated = ensureContactSubmissionExists($conn, $submissionId);
jsonResponse([
    'status' => 'Success',
    'delivery_status' => (string) ($result['status'] ?? 'unknown'),
    'data' => formatContactSubmission(
        $updated,
        contactAttachmentBySubmission($conn, $submissionId),
        contactDeliveryHistory($conn, $submissionId, 20)
    ),
]);
