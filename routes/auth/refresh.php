<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authService.php';

try {
    requireMethod('POST');
    requireCsrfToken();
    applyNoStoreHeaders();

    $admin = rotateRefreshSession($conn);
    $access = issueAccessToken($admin);
    $csrfToken = issueCsrfCookie();

    jsonResponse([
        'status' => 'Success',
        'data' => array_merge(publicAdminPayload($admin), [
            'token' => $access['token'],
            'token_expires_at' => $access['expires_at'],
            'csrf_token' => $csrfToken,
        ]),
    ]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    if ($status >= 500) {
        error_log('Lambert refresh error: ' . $error->getMessage());
    }

    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to restore session.' : $error->getMessage(),
    ], $status);
}
