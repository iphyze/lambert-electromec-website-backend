<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/contactService.php';

requireMethod('GET');
requireAdministrator($conn);
applyNoStoreHeaders();

[$page, $limit, $offset] = paginationInput(20, 100);
$status = strtolower(contentString($_GET['status'] ?? '', 30, false, 'Status'));
$subjectKey = strtolower(contentString($_GET['subject'] ?? '', 100, false, 'Subject'));
$emailStatus = strtolower(contentString($_GET['email_status'] ?? '', 30, false, 'Email status'));
$search = contentString($_GET['search'] ?? '', 160, false, 'Search');
$hasCvRaw = $_GET['has_cv'] ?? null;
$hasCv = $hasCvRaw === null || $hasCvRaw === '' ? null : booleanFlag($hasCvRaw, false);

if ($status !== '' && !in_array($status, ['new', 'read', 'replied', 'archived'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Contact status filter is invalid.'], 422);
}
if ($emailStatus !== '' && !in_array($emailStatus, ['pending', 'sent', 'failed', 'not_configured'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Email delivery status filter is invalid.'], 422);
}
if ($subjectKey !== '' && !contactSubjectByKey($conn, $subjectKey, false)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Subject filter is invalid.'], 422);
}

$where = ['1=1'];
$types = '';
$params = [];

if ($status !== '') {
    $where[] = 'cs.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($subjectKey !== '') {
    $where[] = 'cs.subject_key = ?';
    $types .= 's';
    $params[] = $subjectKey;
}
if ($emailStatus !== '') {
    $where[] = 'cs.email_delivery_status = ?';
    $types .= 's';
    $params[] = $emailStatus;
}
if ($hasCv !== null) {
    $where[] = $hasCv
        ? 'EXISTS (SELECT 1 FROM contact_attachments ca_cv WHERE ca_cv.submission_id = cs.id AND ca_cv.attachment_type = \'cv\')'
        : 'NOT EXISTS (SELECT 1 FROM contact_attachments ca_cv WHERE ca_cv.submission_id = cs.id AND ca_cv.attachment_type = \'cv\')';
}
if ($search !== '') {
    $where[] = '(cs.full_name LIKE ? OR cs.email LIKE ? OR cs.phone LIKE ? OR cs.message LIKE ? OR cs.other_position LIKE ? OR cs.other_location LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);
$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM contact_submissions cs WHERE {$whereSql}");
executePrepared($countStmt, $types, $params);
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$sql = "SELECT cs.*, csub.label_en AS subject_label_en, csub.label_fr AS subject_label_fr,
               au.first_name AS handler_first_name, au.last_name AS handler_last_name, au.email AS handler_email,
               EXISTS(SELECT 1 FROM contact_attachments ca WHERE ca.submission_id = cs.id AND ca.attachment_type = 'cv') AS has_cv
        FROM contact_submissions cs
        INNER JOIN contact_subjects csub ON csub.subject_key = cs.subject_key
        LEFT JOIN admin_users au ON au.id = cs.handled_by
        WHERE {$whereSql}
        ORDER BY FIELD(cs.status, 'new', 'read', 'replied', 'archived'), cs.created_at DESC, cs.id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
executePrepared($stmt, $types . 'ii', array_merge($params, [$limit, $offset]));
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $payload = formatContactSubmission($row);
    $payload['has_cv'] = (bool) $row['has_cv'];
    unset($payload['cv']);
    $items[] = $payload;
}
$stmt->close();

$summaryResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'new') AS new_count,
        SUM(status = 'read') AS read_count,
        SUM(status = 'replied') AS replied_count,
        SUM(status = 'archived') AS archived_count,
        SUM(email_delivery_status = 'failed') AS email_failed_count,
        SUM(email_delivery_status = 'not_configured') AS email_not_configured_count
     FROM contact_submissions"
);
$summary = $summaryResult->fetch_assoc() ?: [];

jsonResponse([
    'status' => 'Success',
    'data' => $items,
    'summary' => [
        'total' => (int) ($summary['total'] ?? 0),
        'new' => (int) ($summary['new_count'] ?? 0),
        'read' => (int) ($summary['read_count'] ?? 0),
        'replied' => (int) ($summary['replied_count'] ?? 0),
        'archived' => (int) ($summary['archived_count'] ?? 0),
        'email_failed' => (int) ($summary['email_failed_count'] ?? 0),
        'email_not_configured' => (int) ($summary['email_not_configured_count'] ?? 0),
    ],
    'pagination' => paginationPayload($page, $limit, $total),
]);
