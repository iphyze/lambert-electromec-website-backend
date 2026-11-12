<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/analyticsService.php';

requireMethod('GET');
requireAdministrator($conn);
applyNoStoreHeaders();

try {
    [$from, $to] = analyticsValidatedRange($_GET['from'] ?? null, $_GET['to'] ?? null);
} catch (InvalidArgumentException $error) {
    jsonResponse(['status' => 'Failed', 'message' => $error->getMessage()], 422);
}

$compare = in_array(strtolower((string) ($_GET['compare'] ?? '1')), ['1', 'true', 'yes'], true);

jsonResponse([
    'status' => 'Success',
    'data' => analyticsReport($conn, $from, $to, $compare),
]);
