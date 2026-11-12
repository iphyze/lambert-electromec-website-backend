<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/aiOperationsService.php';

requireMethod('GET');
requireAdministrator($conn);
applyNoStoreHeaders();

jsonResponse([
    'status' => 'Success',
    'data' => aiOperationsStatus($conn),
]);
