<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/contentService.php';
require_once __DIR__ . '/../../includes/contactService.php';

requireMethod('GET');
$lang = requestedLocale();

jsonResponse([
    'status' => 'Success',
    'data' => publicContactSubjects($conn, $lang),
]);
