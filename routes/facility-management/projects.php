<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/facilityProjectService.php';

requireMethod('GET');
applyNoStoreHeaders();

jsonResponse([
    'status' => 'Success',
    'data' => publicFacilityProjects($conn, requestedLocale()),
]);
