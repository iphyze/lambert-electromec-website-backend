<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

requireMethod('GET');
applyNoStoreHeaders();

try {
    require __DIR__ . '/../includes/connection.php';
    $conn->query('SELECT 1');
} catch (Throwable $error) {
    error_log('Lambert health database check failed: ' . $error->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Service is unavailable.',
        'data' => ['api' => true, 'database' => false],
    ], 503);
}

jsonResponse([
    'status' => 'Success',
    'message' => 'Lambert API is healthy.',
    'data' => [
        'api' => true,
        'database' => true,
        'time' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    ],
]);
