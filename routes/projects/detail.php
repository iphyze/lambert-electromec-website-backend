<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/projectService.php';

requireMethod('GET');
applyNoStoreHeaders();

$project = publicProjectBySlug($conn, (string) ($_GET['slug'] ?? ''), requestedLocale());
if ($project === null) {
    jsonResponse(['status' => 'Failed', 'message' => 'Project not found.'], 404);
}

jsonResponse(['status' => 'Success', 'data' => $project]);
