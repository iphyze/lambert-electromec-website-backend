<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/facilityProjectService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$admin = requireContentAdmin($conn);
applyNoStoreHeaders();

if ($method === 'GET') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 12)));
    $offset = ($page - 1) * $limit;
    $status = strtolower(contentString($_GET['status'] ?? '', 20, false, 'Status'));
    $search = contentString($_GET['search'] ?? '', 180, false, 'Search');

    if ($status !== '' && !in_array($status, ['draft', 'published', 'archived'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Facility project status filter is invalid.'], 422);
    }

    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($status !== '') {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(title_en LIKE ? OR title_fr LIKE ? OR slug LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM facility_projects WHERE {$whereSql}");
    executePrepared($countStmt, $types, $params);
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $stmt = $conn->prepare(
        "SELECT * FROM facility_projects WHERE {$whereSql}
         ORDER BY FIELD(status, 'draft', 'published', 'archived'), display_order ASC, updated_at DESC, id DESC
         LIMIT ? OFFSET ?"
    );
    executePrepared($stmt, $types . 'ii', array_merge($params, [$limit, $offset]));
    $items = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = formatAdminFacilityProject($row);
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
$titleEn = contentString($_POST['title_en'] ?? null, 255, true, 'English title');
$titleFr = contentString($_POST['title_fr'] ?? '', 255, false, 'French title');
$slug = uniqueFacilityProjectSlug($conn, contentString($_POST['slug'] ?? '', 220, false, 'Slug'), $titleEn);
$descriptionEn = contentString($_POST['description_en'] ?? '', 30000, false, 'English description');
$descriptionFr = contentString($_POST['description_fr'] ?? '', 30000, false, 'French description');
$workDoneEn = contentString($_POST['work_done_en'] ?? '', 12000, false, 'English work done');
$workDoneFr = contentString($_POST['work_done_fr'] ?? '', 12000, false, 'French work done');
$displayOrder = max(-100000, min(100000, (int) ($_POST['display_order'] ?? 0)));
$imageFile = $_FILES['image'] ?? null;
$hasImage = is_array($imageFile) && (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($hasImage) facilityProjectImageSpecification($imageFile);
$stored = null;
$adminId = (int) $admin['id'];

try {
    if ($hasImage) $stored = storeFacilityProjectImage($imageFile);
    $stmt = $conn->prepare(
        "INSERT INTO facility_projects (
            slug, title_en, title_fr, description_en, description_fr,
            work_done_en, work_done_fr, image_path, image_original_filename,
            image_mime_type, image_size, status, display_order, created_by, updated_by
         ) VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
                   NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, 'draft', ?, ?, ?)"
    );
    $values = [
        $slug, $titleEn, $titleFr, $descriptionEn, $descriptionFr, $workDoneEn, $workDoneFr,
        $stored['file_path'] ?? '', $stored['original_name'] ?? '', $stored['mime'] ?? '',
        $stored['size'] ?? 0, $displayOrder, $adminId, $adminId,
    ];
    executePrepared($stmt, 'ssssssssssiiii', $values);
    $id = (int) $conn->insert_id;
    $stmt->close();
} catch (Throwable $error) {
    if ($stored) safelyDeleteFacilityProjectImage($stored['file_path']);
    if ($error instanceof mysqli_sql_exception && (int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A Facility Management project with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, $adminId, 'facility_project.created', 'facility_project', $id, ['title_en' => $titleEn, 'slug' => $slug]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Facility Management project created as a draft.',
    'data' => formatAdminFacilityProject(ensureFacilityProjectExists($conn, $id)),
], 201);
