<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/contactService.php';

requireMethod('PATCH');
$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();
$subjectKey = strtolower(contentString($_GET['key'] ?? null, 100, true, 'Subject key'));
$existing = contactSubjectByKey($conn, $subjectKey, false);
if (!$existing) {
    jsonResponse(['status' => 'Failed', 'message' => 'Contact subject routing was not found.'], 404);
}

$body = requestJsonBody(50000);
if (!array_key_exists('recipient_emails', $body) && !array_key_exists('cc_emails', $body)) {
    jsonResponse(['status' => 'Failed', 'message' => 'No routing changes were supplied.'], 422);
}

$recipients = array_key_exists('recipient_emails', $body)
    ? normalizeContactRecipientEmails($body['recipient_emails'], 'Recipient emails')
    : decodeContactRecipientEmails($existing['recipient_emails'] ?? null);
$cc = array_key_exists('cc_emails', $body)
    ? normalizeContactRecipientEmails($body['cc_emails'], 'CC emails')
    : decodeContactRecipientEmails($existing['cc_emails'] ?? null);
$cc = array_values(array_diff($cc, $recipients));

$recipientJson = encodeContactRecipientEmails($recipients);
$ccJson = encodeContactRecipientEmails($cc);
$stmt = $conn->prepare('UPDATE contact_subjects SET recipient_emails = ?, cc_emails = ? WHERE subject_key = ?');
$stmt->bind_param('sss', $recipientJson, $ccJson, $subjectKey);
$stmt->execute();
$stmt->close();

recordAuditLog($conn, (int) $admin['id'], 'contact.routing.updated', 'contact_subject', (int) $existing['id'], [
    'subject_key' => $subjectKey,
    'recipient_count' => count($recipients),
    'cc_count' => count($cc),
]);

$updated = contactSubjectByKey($conn, $subjectKey, false);
jsonResponse([
    'status' => 'Success',
    'message' => 'Contact email routing updated.',
    'data' => formatContactRoutingRow($updated ?: $existing),
]);
