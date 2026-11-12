<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

requireMethod('GET');
applyNoStoreHeaders();

$admin = authenticateAdmin($conn);
jsonResponse([
    'status' => 'Success',
    'data' => publicAdminPayload($admin),
]);
