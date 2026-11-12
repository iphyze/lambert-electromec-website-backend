<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/contactService.php';

requireMethod('GET');
requireAdministrator($conn);
applyNoStoreHeaders();

$result = $conn->query(
    'SELECT id, subject_key, label_en, label_fr, recipient_emails, cc_emails, status, display_order
     FROM contact_subjects
     ORDER BY display_order ASC, id ASC'
);
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = formatContactRoutingRow($row);
}

jsonResponse(['status' => 'Success', 'data' => $items]);
