<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/projectService.php';

requireMethod('GET');
applyNoStoreHeaders();

$result = publicProjects($conn, requestedLocale());
jsonResponse([
    'status' => 'Success',
    'data' => $result['items'],
    'pagination' => $result['pagination'],
]);
