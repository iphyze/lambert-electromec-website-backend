<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/mediaCenterService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$admin = requireContentAdmin($conn);
applyNoStoreHeaders();

if ($method === 'GET') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $status = strtolower(contentString($_GET['status'] ?? '', 20, false, 'Status'));
    $categoryId = optionalPositiveInt($_GET['category_id'] ?? null, 'Category ID');
    $search = contentString($_GET['search'] ?? '', 180, false, 'Search');

    if ($status !== '' && !in_array($status, ['draft', 'published', 'archived'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Media Center status filter is invalid.'], 422);
    }

    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($status !== '') {
        $where[] = 'm.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($categoryId !== null) {
        $where[] = 'm.category_id = ?';
        $types .= 'i';
        $params[] = $categoryId;
    }
    if ($search !== '') {
        $where[] = '(m.title_en LIKE ? OR m.title_fr LIKE ? OR m.slug LIKE ? OR m.reference LIKE ? OR m.original_filename LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM media_items m WHERE {$whereSql}");
    executePrepared($countStmt, $types, $params);
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT m.*, c.slug AS category_slug, c.name_en AS category_name_en,
                   c.name_fr AS category_name_fr, c.status AS category_status
            FROM media_items m
            INNER JOIN media_categories c ON c.id = m.category_id
            WHERE {$whereSql}
            ORDER BY FIELD(m.status, 'draft', 'published', 'archived'), m.updated_at DESC, m.id DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    executePrepared($stmt, $types . 'ii', array_merge($params, [$limit, $offset]));
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = formatAdminMediaItem($row);
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
$categoryId = positiveInt($_POST['category_id'] ?? null, 'Category ID');
if (!mediaCategoryById($conn, $categoryId, true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Select an active Media Center category.'], 422);
}

$titleEn = contentString($_POST['title_en'] ?? null, 255, true, 'English title');
$titleFr = contentString($_POST['title_fr'] ?? '', 255, false, 'French title');
$requestedSlug = contentString($_POST['slug'] ?? '', 220, false, 'Slug');
$slug = uniqueMediaItemSlug($conn, $requestedSlug, $titleEn);
$reference = contentString($_POST['reference'] ?? '', 120, false, 'Reference');
$descriptionEn = contentString($_POST['description_en'] ?? '', 10000, false, 'English description');
$descriptionFr = contentString($_POST['description_fr'] ?? '', 10000, false, 'French description');
$featured = booleanFlag($_POST['featured'] ?? false) ? 1 : 0;
$displayOrder = max(-100000, min(100000, (int) ($_POST['display_order'] ?? 0)));
$files = normalizeUploadedFiles('document', 1);
$document = $files[0];
mediaCenterDocumentSpecification($document);
$coverFile = $_FILES['cover_image'] ?? null;
$hasCover = is_array($coverFile) && (int) ($coverFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($hasCover) {
    mediaCenterCoverImageSpecification($coverFile);
}
$adminId = (int) $admin['id'];
$stored = null;
$storedCover = null;

try {
    $stored = storeMediaCenterDocument($document);
    if ($hasCover) {
        $storedCover = storeMediaCenterCoverImage($coverFile);
    }

    $stmt = $conn->prepare(
        "INSERT INTO media_items (
            category_id, slug, reference, title_en, title_fr, description_en, description_fr,
            file_path, original_filename, mime_type, file_size,
            cover_image_path, cover_image_original_filename, cover_image_mime_type,
            cover_image_size, cover_image_width, cover_image_height,
            featured, display_order, status, created_by, updated_by
         ) VALUES (
            ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), NULLIF(?, ''),
            ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?,
            ?, ?, 'draft', ?, ?
         )"
    );
    $values = [
        $categoryId, $slug, $reference, $titleEn, $titleFr, $descriptionEn, $descriptionFr,
        $stored['file_path'], $stored['original_name'], $stored['mime'], $stored['size'],
        $storedCover['file_path'] ?? '', $storedCover['original_name'] ?? '', $storedCover['mime'] ?? '',
        $storedCover['size'] ?? 0, $storedCover['width'] ?? 0, $storedCover['height'] ?? 0,
        $featured, $displayOrder, $adminId, $adminId,
    ];
    executePrepared($stmt, 'i' . str_repeat('s', 9) . 'i' . str_repeat('s', 3) . 'iiiiiii', $values);
    $itemId = (int) $conn->insert_id;
    $stmt->close();
} catch (Throwable $error) {
    if ($stored) {
        safelyDeleteMediaCenterDocument($stored['file_path']);
    }
    if ($storedCover) {
        safelyDeleteMediaCenterCoverImage($storedCover['file_path']);
    }
    if ($error instanceof mysqli_sql_exception && (int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A Media Center item with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, $adminId, 'media_item.created', 'media_item', $itemId, [
    'title_en' => $titleEn,
    'slug' => $slug,
    'category_id' => $categoryId,
]);
jsonResponse([
    'status' => 'Success',
    'message' => 'Media Center item created as a draft.',
    'data' => formatAdminMediaItem(ensureMediaItemExists($conn, $itemId)),
], 201);
