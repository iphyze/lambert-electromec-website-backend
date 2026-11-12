<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/security.php';

applyApiSecurityHeaders();

$relativePath = normalizedRelativeApiPath();

$routes = [
    '/' => 'routes/welcome.php',
    '/welcome' => 'routes/welcome.php',
    '/health' => 'routes/health.php',

    // Public Lambert AI website assistant. All provider calls remain server-side.
    '/ai/assistant' => 'routes/ai/assistant.php',
    '/ai/project-search' => 'routes/ai/projectSearch.php',

    // Admin authentication foundation (secure JWT + refresh-cookie foundation).
    '/auth/csrf' => 'routes/auth/csrf.php',
    '/auth/login' => 'routes/auth/login.php',
    '/auth/refresh' => 'routes/auth/refresh.php',
    '/auth/restore' => 'routes/auth/restore.php',
    '/auth/logout' => 'routes/auth/logout.php',
    '/auth/me' => 'routes/auth/me.php',

    // Public runtime project API. Published records are available immediately.
    '/projects/divisions' => 'routes/projects/divisions.php',
    '/projects' => 'routes/projects/list.php',
    '/projects/detail' => 'routes/projects/detail.php',

    // Facility Management projects are maintained independently from the main portfolio.
    '/facility-management/projects' => 'routes/facility-management/projects.php',

    // Public runtime Media Center API.
    '/media-center/categories' => 'routes/media-center/categories.php',
    '/media-center' => 'routes/media-center/list.php',
    '/media-center/detail' => 'routes/media-center/detail.php',

    // Public contact workflow. The frontend supplies only stable subject keys;
    // recipient routing is resolved server-side from the database.
    '/contact/subjects' => 'routes/contact/subjects.php',
    '/contact/submit' => 'routes/contact/submit.php',
    '/analytics/collect' => 'routes/analytics/collect.php',

    // Admin dashboard summary.
    '/admin/dashboard' => 'routes/admin/dashboard/summary.php',
    '/admin/ai/status' => 'routes/admin/ai/status.php',
    '/admin/settings' => 'routes/admin/settings/index.php',
    '/admin/settings/test-email' => 'routes/admin/settings/testEmail.php',
    '/admin/analytics/summary' => 'routes/admin/analytics/summary.php',
    '/admin/analytics/export' => 'routes/admin/analytics/export.php',

    // Admin content management API.
    '/admin/divisions' => 'routes/admin/divisions/index.php',
    '/admin/divisions/item' => 'routes/admin/divisions/item.php',

    '/admin/projects' => 'routes/admin/projects/index.php',
    '/admin/projects/item' => 'routes/admin/projects/item.php',
    '/admin/projects/status' => 'routes/admin/projects/status.php',
    '/admin/projects/images/upload' => 'routes/admin/projects/imagesUpload.php',
    '/admin/projects/images/item' => 'routes/admin/projects/imageItem.php',
    '/admin/projects/images/reorder' => 'routes/admin/projects/imagesReorder.php',
    '/admin/projects/home-image' => 'routes/admin/projects/homeImage.php',

    '/admin/facility-projects' => 'routes/admin/facility-projects/index.php',
    '/admin/facility-projects/item' => 'routes/admin/facility-projects/item.php',
    '/admin/facility-projects/image' => 'routes/admin/facility-projects/image.php',
    '/admin/facility-projects/status' => 'routes/admin/facility-projects/status.php',

    '/admin/media-categories' => 'routes/admin/media-categories/index.php',
    '/admin/media-categories/item' => 'routes/admin/media-categories/item.php',
    '/admin/media-center' => 'routes/admin/media-center/index.php',
    '/admin/media-center/item' => 'routes/admin/media-center/item.php',
    '/admin/media-center/file' => 'routes/admin/media-center/file.php',
    '/admin/media-center/cover' => 'routes/admin/media-center/cover.php',
    '/admin/media-center/status' => 'routes/admin/media-center/status.php',

    // Contact submissions, protected CV access and subject-based email routing.
    '/admin/contact' => 'routes/admin/contact/index.php',
    '/admin/contact/item' => 'routes/admin/contact/item.php',
    '/admin/contact/status' => 'routes/admin/contact/status.php',
    '/admin/contact/cv' => 'routes/admin/contact/cv.php',
    '/admin/contact/email' => 'routes/admin/contact/email.php',
    '/admin/contact/ai-analysis' => 'routes/admin/contact/aiAnalysis.php',
    '/admin/contact-routing' => 'routes/admin/contact-routing/index.php',
    '/admin/contact-routing/item' => 'routes/admin/contact-routing/item.php',

    // Super Admin-only administrator account management.
    '/admin/administrators' => 'routes/admin/administrators/index.php',
    '/admin/administrators/item' => 'routes/admin/administrators/item.php',
    '/admin/administrators/security' => 'routes/admin/administrators/security.php',
];

if (isset($routes[$relativePath])) {
    require __DIR__ . '/' . $routes[$relativePath];
    exit;
}

jsonResponse([
    'status' => 'Failed',
    'message' => 'Endpoint not found.',
], 404);
