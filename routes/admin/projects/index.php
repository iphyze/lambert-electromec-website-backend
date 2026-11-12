<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$admin = requireContentAdmin($conn);
applyNoStoreHeaders();

if ($method === 'GET') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $status = strtolower(contentString($_GET['status'] ?? '', 20, false, 'Status'));
    $divisionId = optionalPositiveInt($_GET['division_id'] ?? null, 'Division ID');
    $search = contentString($_GET['search'] ?? '', 180, false, 'Search');

    if ($status !== '' && !in_array($status, ['draft', 'published', 'archived'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Project status filter is invalid.'], 422);
    }

    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($status !== '') {
        $where[] = 'p.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($divisionId !== null) {
        $where[] = 'p.division_id = ?';
        $types .= 'i';
        $params[] = $divisionId;
    }
    if ($search !== '') {
        $where[] = '(p.title_en LIKE ? OR p.title_fr LIKE ? OR p.slug LIKE ? OR p.location_en LIKE ? OR p.location_fr LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM projects p WHERE {$whereSql}");
    executePrepared($countStmt, $types, $params);
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT p.*, d.division_key, d.slug AS division_slug,
                   d.name_en AS division_name_en, d.name_fr AS division_name_fr,
                   d.status AS division_status,
                   (SELECT COUNT(*) FROM project_images pic WHERE pic.project_id = p.id) AS image_count,
                   fi.id AS featured_image_id, fi.file_path AS featured_image_path,
                   fi.alt_text_en AS featured_image_alt_en, fi.alt_text_fr AS featured_image_alt_fr
            FROM projects p
            INNER JOIN divisions d ON d.id = p.division_id
            LEFT JOIN project_images fi ON fi.id = (
                SELECT pi.id FROM project_images pi
                WHERE pi.project_id = p.id AND pi.is_featured = 1
                ORDER BY pi.id ASC LIMIT 1
            )
            WHERE {$whereSql}
            ORDER BY FIELD(p.status, 'draft', 'published', 'archived'), p.updated_at DESC, p.id DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    executePrepared($stmt, $types . 'ii', array_merge($params, [$limit, $offset]));
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = formatAdminProjectRow($row);
    }
    $stmt->close();

    jsonResponse([
        'status' => 'Success',
        'data' => $items,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $limit),
        ],
    ]);
}

if ($method !== 'POST') {
    requireMethod('GET', 'POST');
}

requireCsrfToken();
$body = requestJsonBody(500000);
$divisionId = positiveInt($body['division_id'] ?? null, 'Division ID');
if (!divisionById($conn, $divisionId, true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Select an active division.'], 422);
}

$titleEn = contentString($body['title_en'] ?? null, 255, true, 'English title');
$titleFr = contentString($body['title_fr'] ?? '', 255, false, 'French title');
$requestedSlug = contentString($body['slug'] ?? '', 220, false, 'Slug');
$slug = uniqueProjectSlug($conn, $requestedSlug, $titleEn);
$summaryEn = contentString($body['summary_en'] ?? '', 10000, false, 'English summary');
$summaryFr = contentString($body['summary_fr'] ?? '', 10000, false, 'French summary');
$descriptionEn = contentString($body['description_en'] ?? '', 200000, false, 'English description');
$descriptionFr = contentString($body['description_fr'] ?? '', 200000, false, 'French description');
$locationEn = contentString($body['location_en'] ?? '', 255, false, 'English location');
$locationFr = contentString($body['location_fr'] ?? '', 255, false, 'French location');
$workDoneEn = contentString($body['work_done_en'] ?? '', 20000, false, 'English work done');
$workDoneFr = contentString($body['work_done_fr'] ?? '', 20000, false, 'French work done');
$projectYear = contentString($body['project_year'] ?? '', 100, false, 'Project year');
$seoTitleEn = contentString($body['seo_title_en'] ?? '', 255, false, 'English SEO title');
$seoTitleFr = contentString($body['seo_title_fr'] ?? '', 255, false, 'French SEO title');
$seoDescriptionEn = contentString($body['seo_description_en'] ?? '', 500, false, 'English SEO description');
$seoDescriptionFr = contentString($body['seo_description_fr'] ?? '', 500, false, 'French SEO description');
$featured = booleanFlag($body['featured'] ?? false) ? 1 : 0;
assertFeaturedProjectCapacity($conn, $featured === 1);
$displayOrder = max(-100000, min(100000, (int) ($body['display_order'] ?? 0)));
$adminId = (int) $admin['id'];

$sql = "INSERT INTO projects (
            division_id, slug, title_en, title_fr, summary_en, summary_fr, description_en, description_fr,
            location_en, location_fr, work_done_en, work_done_fr, project_year,
            seo_title_en, seo_title_fr, seo_description_en, seo_description_fr,
            featured, display_order, status, created_by, updated_by
        ) VALUES (
            ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
            NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
            NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
            ?, ?, 'draft', ?, ?
        )";

try {
    $stmt = $conn->prepare($sql);
    $values = [
        $divisionId, $slug, $titleEn, $titleFr, $summaryEn, $summaryFr, $descriptionEn, $descriptionFr,
        $locationEn, $locationFr, $workDoneEn, $workDoneFr, $projectYear,
        $seoTitleEn, $seoTitleFr, $seoDescriptionEn, $seoDescriptionFr,
        $featured, $displayOrder, $adminId, $adminId,
    ];
    executePrepared($stmt, 'i' . str_repeat('s', 16) . 'iiii', $values);
    $projectId = (int) $conn->insert_id;
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A project with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, $adminId, 'project.created', 'project', $projectId, [
    'title_en' => $titleEn,
    'slug' => $slug,
    'division_id' => $divisionId,
]);

$payload = formatAdminProjectRow(ensureProjectExists($conn, $projectId));
$payload['images'] = [];
jsonResponse([
    'status' => 'Success',
    'message' => 'Project created as a draft.',
    'data' => $payload,
], 201);
