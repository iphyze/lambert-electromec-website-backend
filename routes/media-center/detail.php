<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/mediaCenterService.php';

requireMethod('GET');
applyNoStoreHeaders();

$item = publicMediaBySlug($conn, (string) ($_GET['slug'] ?? ''), requestedLocale());
if ($item === null) {
    jsonResponse(['status' => 'Failed', 'message' => 'Media resource not found.'], 404);
}

jsonResponse(['status' => 'Success', 'data' => $item]);
