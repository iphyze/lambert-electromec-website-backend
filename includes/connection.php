<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/security.php';

function databaseFailure(Throwable $error): never
{
    error_log('Lambert database error: ' . $error->getMessage());
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Internal server error. Database connection unavailable.',
    ], 500);
}

if (!extension_loaded('mysqli')) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Internal server error. Database support is unavailable.',
    ], 500);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $host = (string) envValue('DB_HOST', 'localhost');
    $port = (int) envValue('DB_PORT', 3306);
    $username = (string) envValue('DB_USER', 'root');
    $password = (string) envValue('DB_PASSWORD', '');
    $database = trim((string) envValue('DB_NAME', 'lambert_electromec'));
    $dbTimeZone = trim((string) envValue('DB_TIME_ZONE', '+01:00'));

    if ($database === '') {
        throw new RuntimeException('DB_NAME is not configured.');
    }

    $conn = new mysqli($host, $username, $password, $database, $port);
    $conn->set_charset('utf8mb4');

    if ($dbTimeZone !== '') {
        $stmt = $conn->prepare('SET time_zone = ?');
        $stmt->bind_param('s', $dbTimeZone);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $error) {
    databaseFailure($error);
}
