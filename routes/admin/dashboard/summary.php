<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/dashboardService.php';

requireMethod('GET');
$admin = requireContentAdmin($conn);
applyNoStoreHeaders();

$isAdministrator = in_array((string) $admin['role'], ['super_admin', 'admin'], true);
$isSuperAdmin = (string) $admin['role'] === 'super_admin';

$projects = dashboardStatusCounts($conn, 'projects', ['draft', 'published', 'archived']);
$media = dashboardStatusCounts($conn, 'media_items', ['draft', 'published', 'archived']);
$divisions = dashboardStatusCounts($conn, 'divisions', ['active', 'inactive']);
$mediaCategories = dashboardStatusCounts($conn, 'media_categories', ['active', 'inactive']);

$data = [
    'projects' => [
        'total' => array_sum($projects),
        'by_status' => $projects,
    ],
    'media_center' => [
        'total' => array_sum($media),
        'by_status' => $media,
    ],
    'divisions' => [
        'total' => array_sum($divisions),
        'by_status' => $divisions,
    ],
    'media_categories' => [
        'total' => array_sum($mediaCategories),
        'by_status' => $mediaCategories,
    ],
    'recent_activity' => dashboardRecentActivity($conn, 10, $isAdministrator),
    'permissions' => [
        'manage_projects' => true,
        'manage_media_center' => true,
        'manage_divisions' => $isAdministrator,
        'publish_content' => $isAdministrator,
        'manage_contacts' => $isAdministrator,
        'configure_contact_routing' => $isAdministrator,
        'manage_admin_users' => $isSuperAdmin,
    ],
];

if ($isAdministrator) {
    $contacts = dashboardStatusCounts($conn, 'contact_submissions', ['new', 'read', 'replied', 'archived']);
    $data['contacts'] = [
        'total' => array_sum($contacts),
        'by_status' => $contacts,
    ];
}

if ($isSuperAdmin) {
    $data['admin_users'] = dashboardAdminUsers($conn);
}

jsonResponse([
    'status' => 'Success',
    'data' => $data,
]);
