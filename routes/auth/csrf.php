<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';

requireMethod('GET');
requireTrustedRequestOrigin();
applyNoStoreHeaders();

jsonResponse([
    'status' => 'Success',
    'data' => ['csrf_token' => issueCsrfCookie()],
]);
