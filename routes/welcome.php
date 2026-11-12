<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';

requireMethod('GET');

jsonResponse([
    'status' => 'Success',
    'message' => 'Lambert Electromec API.',
    'data' => [
        'version' => '1.0.0',
        'runtime' => 'PHP',
    ],
]);
