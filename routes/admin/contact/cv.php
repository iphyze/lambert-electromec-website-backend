<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/contentService.php';
require_once __DIR__ . '/../../../includes/contactService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('GET');
requireAdministrator($conn);
$attachmentId = positiveInt($_GET['id'] ?? null, 'CV attachment ID');
$attachment = contactAttachmentById($conn, $attachmentId);
if (!$attachment) {
    jsonResponse(['status' => 'Failed', 'message' => 'CV attachment not found.'], 404);
}

$absolutePath = resolveCvUploadPath((string) $attachment['file_path']);
if ($absolutePath === null) {
    jsonResponse(['status' => 'Failed', 'message' => 'CV file is unavailable.'], 404);
}

$original = (string) $attachment['original_filename'];
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original) ?: 'cv';

header_remove('Content-Type');
header('Content-Type: ' . (string) $attachment['mime_type']);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($original));
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($absolutePath);
exit;
