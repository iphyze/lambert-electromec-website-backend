<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/projectService.php';
require_once __DIR__ . '/../../../includes/uploadService.php';

requireMethod('GET', 'PATCH', 'DELETE');
$admin = requireContentAdmin($conn);
applyNoStoreHeaders();
$projectId = positiveInt($_GET['id'] ?? null, 'Project ID');
$existing = ensureProjectExists($conn, $projectId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $payload = formatAdminProjectRow($existing);
    $payload['images'] = adminProjectImages($conn, $projectId);
    jsonResponse(['status' => 'Success', 'data' => $payload]);
}

requireCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!in_array((string) $admin['role'], ['super_admin', 'admin'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Only an Admin can archive projects.'], 403);
    }

    $hardDelete = booleanFlag($_GET['hard'] ?? false);
    if (!$hardDelete) {
        $adminId = (int) $admin['id'];
        $stmt = $conn->prepare("UPDATE projects SET status = 'archived', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $projectId);
        $stmt->execute();
        $stmt->close();
        recordAuditLog($conn, $adminId, 'project.archived', 'project', $projectId, [
            'title_en' => $existing['title_en'],
        ]);
        jsonResponse(['status' => 'Success', 'message' => 'Project archived successfully.']);
    }

    if ((string) $admin['role'] !== 'super_admin') {
        jsonResponse(['status' => 'Failed', 'message' => 'Only a Super Admin can permanently delete projects.'], 403);
    }

    $stmt = $conn->prepare('SELECT file_path FROM project_images WHERE project_id = ?');
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $files = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['file_path'])) {
            $files[] = (string) $row['file_path'];
        }
    }
    $stmt->close();

    $homeImage = adminProjectHomeImage($conn, $projectId);
    $homeImagePath = !empty($homeImage['file_path']) ? (string) $homeImage['file_path'] : null;

    $stmt = $conn->prepare('DELETE FROM projects WHERE id = ?');
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $stmt->close();

    foreach (array_unique($files) as $filePath) {
        safelyDeleteProjectImage($filePath);
    }
    safelyDeleteProjectHomeImage($conn, $homeImagePath);

    recordAuditLog($conn, (int) $admin['id'], 'project.deleted', 'project', $projectId, [
        'title_en' => $existing['title_en'],
    ]);
    jsonResponse(['status' => 'Success', 'message' => 'Project permanently deleted.']);
}

assertCanModifyProject($admin, $existing);
$body = requestJsonBody(500000);

$divisionId = array_key_exists('division_id', $body)
    ? positiveInt($body['division_id'], 'Division ID')
    : (int) $existing['division_id'];
if (!divisionById($conn, $divisionId, true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Select an active division.'], 422);
}

$titleEn = array_key_exists('title_en', $body)
    ? contentString($body['title_en'], 255, true, 'English title')
    : (string) $existing['title_en'];
$titleFr = array_key_exists('title_fr', $body)
    ? contentString($body['title_fr'], 255, false, 'French title')
    : (string) ($existing['title_fr'] ?? '');
$requestedSlug = array_key_exists('slug', $body)
    ? contentString($body['slug'], 220, false, 'Slug')
    : (string) $existing['slug'];
if ((string) $existing['status'] === 'published' && $requestedSlug !== (string) $existing['slug']) {
    jsonResponse([
        'status' => 'Failed',
        'message' => 'Unpublish the project before changing its URL slug.',
    ], 409);
}
$slug = uniqueProjectSlug($conn, $requestedSlug, $titleEn, $projectId);

$field = static function (array $body, string $key, mixed $existingValue, int $max, string $label): string {
    return array_key_exists($key, $body)
        ? contentString($body[$key], $max, false, $label)
        : (string) ($existingValue ?? '');
};

$summaryEn = $field($body, 'summary_en', $existing['summary_en'] ?? '', 10000, 'English summary');
$summaryFr = $field($body, 'summary_fr', $existing['summary_fr'] ?? '', 10000, 'French summary');
$descriptionEn = $field($body, 'description_en', $existing['description_en'] ?? '', 200000, 'English description');
$descriptionFr = $field($body, 'description_fr', $existing['description_fr'] ?? '', 200000, 'French description');
$locationEn = $field($body, 'location_en', $existing['location_en'] ?? '', 255, 'English location');
$locationFr = $field($body, 'location_fr', $existing['location_fr'] ?? '', 255, 'French location');
$workDoneEn = $field($body, 'work_done_en', $existing['work_done_en'] ?? '', 20000, 'English work done');
$workDoneFr = $field($body, 'work_done_fr', $existing['work_done_fr'] ?? '', 20000, 'French work done');
$projectYear = $field($body, 'project_year', $existing['project_year'] ?? '', 100, 'Project year');
$seoTitleEn = $field($body, 'seo_title_en', $existing['seo_title_en'] ?? '', 255, 'English SEO title');
$seoTitleFr = $field($body, 'seo_title_fr', $existing['seo_title_fr'] ?? '', 255, 'French SEO title');
$seoDescriptionEn = $field($body, 'seo_description_en', $existing['seo_description_en'] ?? '', 500, 'English SEO description');
$seoDescriptionFr = $field($body, 'seo_description_fr', $existing['seo_description_fr'] ?? '', 500, 'French SEO description');
$featured = array_key_exists('featured', $body)
    ? (booleanFlag($body['featured']) ? 1 : 0)
    : (int) $existing['featured'];
assertFeaturedProjectCapacity($conn, $featured === 1, $projectId);
$displayOrder = array_key_exists('display_order', $body)
    ? max(-100000, min(100000, (int) $body['display_order']))
    : (int) $existing['display_order'];
$adminId = (int) $admin['id'];

$sql = "UPDATE projects SET
            division_id = ?, slug = ?, title_en = ?, title_fr = ?,
            summary_en = NULLIF(?, ''), summary_fr = NULLIF(?, ''),
            description_en = NULLIF(?, ''), description_fr = NULLIF(?, ''),
            location_en = NULLIF(?, ''), location_fr = NULLIF(?, ''),
            work_done_en = NULLIF(?, ''), work_done_fr = NULLIF(?, ''),
            project_year = NULLIF(?, ''), seo_title_en = NULLIF(?, ''), seo_title_fr = NULLIF(?, ''),
            seo_description_en = NULLIF(?, ''), seo_description_fr = NULLIF(?, ''),
            featured = ?, display_order = ?, updated_by = ?
        WHERE id = ?";

try {
    $stmt = $conn->prepare($sql);
    $values = [
        $divisionId, $slug, $titleEn, $titleFr, $summaryEn, $summaryFr, $descriptionEn, $descriptionFr,
        $locationEn, $locationFr, $workDoneEn, $workDoneFr, $projectYear,
        $seoTitleEn, $seoTitleFr, $seoDescriptionEn, $seoDescriptionFr,
        $featured, $displayOrder, $adminId, $projectId,
    ];
    executePrepared($stmt, 'i' . str_repeat('s', 16) . 'iiii', $values);
    $stmt->close();

    $stmt = $conn->prepare(
        "UPDATE project_home_images
         SET alt_text_en = NULLIF(?, ''), alt_text_fr = NULLIF(?, '')
         WHERE project_id = ?"
    );
    $stmt->bind_param('ssi', $titleEn, $titleFr, $projectId);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        jsonResponse(['status' => 'Failed', 'message' => 'A project with that slug already exists.'], 409);
    }
    throw $error;
}

recordAuditLog($conn, $adminId, 'project.updated', 'project', $projectId, [
    'title_en' => $titleEn,
    'slug' => $slug,
    'division_id' => $divisionId,
]);

$payload = formatAdminProjectRow(ensureProjectExists($conn, $projectId));
$payload['images'] = adminProjectImages($conn, $projectId);
jsonResponse(['status' => 'Success', 'message' => 'Project updated successfully.', 'data' => $payload]);
