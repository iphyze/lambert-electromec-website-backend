<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authService.php';

try {
    requireMethod('POST');
    // Session bootstrap is allowed only from a configured first-party origin.
    // It rotates an existing HttpOnly refresh session and does not accept any
    // user-controlled mutation payload, so it does not depend on a pre-existing
    // CSRF token that is intentionally memory-only in the admin frontend.
    requireTrustedRequestOrigin();
    applyNoStoreHeaders();

    if (currentRefreshCookie() === '') {
        jsonResponse([
            'status' => 'Success',
            'data' => ['authenticated' => false],
        ]);
    }

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
        error_log('Lambert session restore error: ' . $error->getMessage());
    }

    jsonResponse([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to restore session.' : $error->getMessage(),
    ], $status);
}
