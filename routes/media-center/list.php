<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/mediaCenterService.php';

requireMethod('GET');
applyNoStoreHeaders();

$result = publicMediaItems($conn, requestedLocale());
jsonResponse([
    'status' => 'Success',
    'data' => $result['items'],
    'pagination' => $result['pagination'],
]);
