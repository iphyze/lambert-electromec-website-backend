<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authService.php';

requireMethod('POST');
requireCsrfToken();
applyNoStoreHeaders();

revokeCurrentRefreshFamily($conn);
jsonResponse(['status' => 'Success', 'message' => 'Logged out successfully.']);
