<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/projectService.php';

requireMethod('GET');
applyNoStoreHeaders();

jsonResponse([
    'status' => 'Success',
    'data' => publicProjectDivisions($conn, requestedLocale()),
]);
