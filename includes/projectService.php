<?php

declare(strict_types=1);

require_once __DIR__ . '/contentService.php';

const HOME_FEATURED_PROJECT_LIMIT = 10;

function activeFeaturedProjectCount(mysqli $conn, ?int $excludeProjectId = null): int
{
    if ($excludeProjectId !== null) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM projects WHERE featured = 1 AND status <> 'archived' AND id <> ?");
        $stmt->bind_param('i', $excludeProjectId);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $count;
    }

    $result = $conn->query("SELECT COUNT(*) AS total FROM projects WHERE featured = 1 AND status <> 'archived'");
    return (int) ($result->fetch_assoc()['total'] ?? 0);
}

function assertFeaturedProjectCapacity(mysqli $conn, bool $featured, ?int $projectId = null): void
{
    if (!$featured) {
        return;
    }

    if (activeFeaturedProjectCount($conn, $projectId) >= HOME_FEATURED_PROJECT_LIMIT) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'A maximum of ' . HOME_FEATURED_PROJECT_LIMIT . ' projects can be featured on the homepage. Remove another project from the homepage first.',
        ], 422);
    }
}

function projectLocaleColumns(string $lang): array
{
    if ($lang === 'fr') {
        return [
            'title' => "COALESCE(NULLIF(p.title_fr, ''), p.title_en)",
            'summary' => "COALESCE(NULLIF(p.summary_fr, ''), p.summary_en)",
            'description' => "COALESCE(NULLIF(p.description_fr, ''), p.description_en)",
            'location' => "COALESCE(NULLIF(p.location_fr, ''), p.location_en)",
            'work_done' => "COALESCE(NULLIF(p.work_done_fr, ''), p.work_done_en)",
            'division_name' => "COALESCE(NULLIF(d.name_fr, ''), d.name_en)",
            'image_alt' => "COALESCE(NULLIF(pi.alt_text_fr, ''), pi.alt_text_en)",
            'image_caption' => "COALESCE(NULLIF(pi.caption_fr, ''), pi.caption_en)",
        ];
    }

    return [
        'title' => 'p.title_en',
        'summary' => 'p.summary_en',
        'description' => 'p.description_en',
        'location' => 'p.location_en',
        'work_done' => 'p.work_done_en',
        'division_name' => 'd.name_en',
        'image_alt' => 'pi.alt_text_en',
        'image_caption' => 'pi.caption_en',
    ];
}

function formatProjectImageRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'url' => publicAssetUrl((string) $row['file_path']),
        'alt_text' => (string) ($row['alt_text'] ?? ''),
        'caption' => (string) ($row['caption'] ?? ''),
        'is_featured' => (bool) ($row['is_featured'] ?? false),
        'display_order' => (int) ($row['display_order'] ?? 0),
    ];
}

function projectImages(mysqli $conn, int $projectId, string $lang): array
{
    $columns = projectLocaleColumns($lang);
    $sql = "SELECT pi.id, pi.file_path, {$columns['image_alt']} AS alt_text,
                   {$columns['image_caption']} AS caption, pi.is_featured, pi.display_order
            FROM project_images pi
            WHERE pi.project_id = ?
            ORDER BY pi.is_featured DESC, pi.display_order ASC, pi.id ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $images = [];
    while ($row = $result->fetch_assoc()) {
        $images[] = formatProjectImageRow($row);
    }
    $stmt->close();
    return $images;
}

function publicProjectDivisions(mysqli $conn, string $lang): array
{
    $nameColumn = $lang === 'fr' ? "COALESCE(NULLIF(name_fr, ''), name_en)" : 'name_en';
    $result = $conn->query(
        "SELECT id, division_key, slug, {$nameColumn} AS name, display_order
         FROM divisions
         WHERE status = 'active'
         ORDER BY display_order ASC, id ASC"
    );

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id'],
            'key' => (string) $row['division_key'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'display_order' => (int) $row['display_order'],
        ];
    }
    return $items;
}

function publicProjects(mysqli $conn, string $lang): array
{
    [$page, $limit, $offset] = paginationInput(12, 48);
    $columns = projectLocaleColumns($lang);
    $homeImageAltColumn = $lang === 'fr'
        ? "COALESCE(NULLIF(phi.alt_text_fr, ''), phi.alt_text_en)"
        : 'phi.alt_text_en';

    $division = trim((string) ($_GET['division'] ?? ''));
    $search = trim((string) ($_GET['search'] ?? ''));
    $featuredOnly = booleanFlag($_GET['featured'] ?? null, false);

    if (mb_strlen($division) > 120 || mb_strlen($search) > 180) {
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid filter value.'], 422);
    }

    $conditions = ["p.status = 'published'", '(p.published_at IS NULL OR p.published_at <= NOW())'];
    $types = '';
    $values = [];

    if ($division !== '') {
        $conditions[] = '(d.slug = ? OR d.division_key = ?)';
        $types .= 'ss';
        $values[] = $division;
        $values[] = $division;
    }

    if ($featuredOnly) {
        $conditions[] = 'p.featured = 1';
    }

    if ($search !== '') {
        $needle = '%' . $search . '%';
        $conditions[] = "({$columns['title']} LIKE ? OR {$columns['summary']} LIKE ? OR {$columns['location']} LIKE ?)";
        $types .= 'sss';
        array_push($values, $needle, $needle, $needle);
    }

    $where = implode(' AND ', $conditions);

    $countSql = "SELECT COUNT(*) AS total
                 FROM projects p
                 INNER JOIN divisions d ON d.id = p.division_id
                 WHERE {$where}";
    $countStmt = $conn->prepare($countSql);
    executePrepared($countStmt, $types, $values);
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $orderBy = $featuredOnly
        ? 'p.display_order ASC, COALESCE(p.published_at, p.created_at) DESC, p.id DESC'
        : 'p.featured DESC, COALESCE(p.published_at, p.created_at) DESC, p.display_order ASC, p.id DESC';

    $sql = "SELECT p.id, p.slug, {$columns['title']} AS title,
                   COALESCE(NULLIF({$columns['summary']}, ''), {$columns['description']}) AS summary,
                   {$columns['location']} AS location, p.project_year, p.featured, p.published_at,
                   d.id AS division_id, d.division_key, d.slug AS division_slug,
                   {$columns['division_name']} AS division_name,
                   pi.id AS image_id, pi.file_path AS image_path,
                   {$columns['image_alt']} AS image_alt, pi.display_order AS image_order,
                   phi.id AS home_image_id, phi.file_path AS home_image_path,
                   {$homeImageAltColumn} AS home_image_alt,
                   (SELECT COUNT(*) FROM project_images pic WHERE pic.project_id = p.id) AS image_count
            FROM projects p
            INNER JOIN divisions d ON d.id = p.division_id
            LEFT JOIN project_images pi ON pi.project_id = p.id AND pi.is_featured = 1
            LEFT JOIN project_home_images phi ON phi.project_id = p.id
            WHERE {$where}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $listTypes = $types . 'ii';
    $listValues = array_merge($values, [$limit, $offset]);
    executePrepared($stmt, $listTypes, $listValues);

    $items = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $featuredImage = null;
        if (!empty($row['image_id']) && !empty($row['image_path'])) {
            $featuredImage = [
                'id' => (int) $row['image_id'],
                'url' => publicAssetUrl((string) $row['image_path']),
                'alt_text' => (string) ($row['image_alt'] ?? ''),
                'display_order' => (int) ($row['image_order'] ?? 0),
            ];
        }

        $homeImage = null;
        if (!empty($row['home_image_id']) && !empty($row['home_image_path'])) {
            $homeImage = [
                'id' => (int) $row['home_image_id'],
                'url' => publicAssetUrl((string) $row['home_image_path']),
                'alt_text' => (string) ($row['home_image_alt'] ?? ''),
            ];
        }

        $items[] = [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'summary' => (string) ($row['summary'] ?? ''),
            'location' => (string) ($row['location'] ?? ''),
            'project_year' => $row['project_year'] ?: null,
            'featured' => (bool) $row['featured'],
            'published_at' => $row['published_at'] ?: null,
            'division' => [
                'id' => (int) $row['division_id'],
                'key' => (string) $row['division_key'],
                'slug' => (string) $row['division_slug'],
                'name' => (string) $row['division_name'],
            ],
            'featured_image' => $featuredImage,
            'home_image' => $homeImage,
            'image_count' => (int) $row['image_count'],
        ];
    }
    $stmt->close();

    return [
        'items' => $items,
        'pagination' => paginationPayload($page, $limit, $total),
    ];
}

function publicProjectBySlug(mysqli $conn, string $slug, string $lang): ?array
{
    $slug = trim($slug);
    if ($slug === '' || mb_strlen($slug) > 220) {
        return null;
    }

    $columns = projectLocaleColumns($lang);
    $sql = "SELECT p.id, p.slug, {$columns['title']} AS title,
                   COALESCE(NULLIF({$columns['summary']}, ''), {$columns['description']}) AS summary,
                   {$columns['description']} AS description, {$columns['location']} AS location,
                   {$columns['work_done']} AS work_done, p.project_year, p.featured, p.published_at,
                   p.seo_title_en, p.seo_title_fr, p.seo_description_en, p.seo_description_fr,
                   d.id AS division_id, d.division_key, d.slug AS division_slug,
                   {$columns['division_name']} AS division_name
            FROM projects p
            INNER JOIN divisions d ON d.id = p.division_id
            WHERE p.slug = ? AND p.status = 'published'
              AND (p.published_at IS NULL OR p.published_at <= NOW())
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $images = projectImages($conn, (int) $row['id'], $lang);
    $featuredImage = null;
    foreach ($images as $image) {
        if ($image['is_featured']) {
            $featuredImage = $image;
            break;
        }
    }
    if ($featuredImage === null && $images !== []) {
        $featuredImage = $images[0];
    }

    $seoTitle = $lang === 'fr' ? (string) ($row['seo_title_fr'] ?? '') : (string) ($row['seo_title_en'] ?? '');
    $seoDescription = $lang === 'fr'
        ? (string) ($row['seo_description_fr'] ?? '')
        : (string) ($row['seo_description_en'] ?? '');
    if ($seoDescription === '') {
        $seoDescription = (string) ($row['summary'] ?? $row['description'] ?? '');
    }

    return [
        'id' => (int) $row['id'],
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'summary' => (string) ($row['summary'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'work_done' => (string) ($row['work_done'] ?? ''),
        'project_year' => $row['project_year'] ?: null,
        'featured' => (bool) $row['featured'],
        'published_at' => $row['published_at'] ?: null,
        'division' => [
            'id' => (int) $row['division_id'],
            'key' => (string) $row['division_key'],
            'slug' => (string) $row['division_slug'],
            'name' => (string) $row['division_name'],
        ],
        'featured_image' => $featuredImage,
        'images' => $images,
        'seo' => [
            'title' => $seoTitle !== '' ? $seoTitle : (string) $row['title'],
            'description' => $seoDescription !== '' ? $seoDescription : (string) ($row['summary'] ?? ''),
        ],
    ];
}

function uniqueProjectSlug(mysqli $conn, string $requested, string $title, ?int $excludeId = null): string
{
    $base = substr(slugifyContent($requested !== '' ? $requested : $title, 'project'), 0, 220);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $conn->prepare('SELECT id FROM projects WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $candidate, $excludeId);
        } else {
            $stmt = $conn->prepare('SELECT id FROM projects WHERE slug = ? LIMIT 1');
            $stmt->bind_param('s', $candidate);
        }
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $candidate;
        }

        $suffixText = '-' . $suffix++;
        $candidate = substr($base, 0, 220 - strlen($suffixText)) . $suffixText;
    }
}

function divisionById(mysqli $conn, int $divisionId, bool $activeOnly = false): ?array
{
    $sql = 'SELECT id, division_key, slug, name_en, name_fr, status, display_order, created_at, updated_at
            FROM divisions WHERE id = ?';
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $divisionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) {
        return null;
    }

    $row['id'] = (int) $row['id'];
    $row['display_order'] = (int) $row['display_order'];
    return $row;
}

function uniqueDivisionSlug(mysqli $conn, string $requested, string $name, ?int $excludeId = null): string
{
    $base = substr(slugifyContent($requested !== '' ? $requested : $name, 'division'), 0, 120);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $conn->prepare('SELECT id FROM divisions WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $candidate, $excludeId);
        } else {
            $stmt = $conn->prepare('SELECT id FROM divisions WHERE slug = ? LIMIT 1');
            $stmt->bind_param('s', $candidate);
        }
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$exists) {
            return $candidate;
        }

        $suffixText = '-' . $suffix++;
        $candidate = substr($base, 0, 120 - strlen($suffixText)) . $suffixText;
    }
}

function divisionKeyFromValue(string $value): string
{
    $slug = slugifyContent($value, 'division');
    $parts = array_values(array_filter(explode('-', $slug)));
    if ($parts === []) {
        return 'division';
    }

    $key = array_shift($parts);
    foreach ($parts as $part) {
        $key .= ucfirst($part);
    }
    return substr($key, 0, 80);
}

function uniqueDivisionKey(mysqli $conn, string $requested, string $name): string
{
    $raw = trim($requested);
    $base = $raw !== '' ? $raw : divisionKeyFromValue($name);
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,79}$/', $base)) {
        $base = divisionKeyFromValue($base);
    }
    $base = substr($base !== '' ? $base : 'division', 0, 80);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        $stmt = $conn->prepare('SELECT id FROM divisions WHERE division_key = ? LIMIT 1');
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $candidate;
        }

        $suffixText = (string) $suffix++;
        $candidate = substr($base, 0, 80 - strlen($suffixText)) . $suffixText;
    }
}

function formatAdminProjectImage(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'project_id' => (int) $row['project_id'],
        'file_path' => (string) $row['file_path'],
        'url' => publicAssetUrl((string) $row['file_path']),
        'original_filename' => $row['original_filename'] ?: null,
        'mime_type' => $row['mime_type'] ?: null,
        'file_size' => (int) ($row['file_size'] ?? 0),
        'alt_text_en' => $row['alt_text_en'] ?: null,
        'alt_text_fr' => $row['alt_text_fr'] ?: null,
        'caption_en' => $row['caption_en'] ?: null,
        'caption_fr' => $row['caption_fr'] ?: null,
        'is_featured' => (bool) $row['is_featured'],
        'display_order' => (int) $row['display_order'],
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function adminProjectImages(mysqli $conn, int $projectId): array
{
    $stmt = $conn->prepare(
        'SELECT id, project_id, file_path, original_filename, mime_type, file_size,
                alt_text_en, alt_text_fr, caption_en, caption_fr, is_featured, display_order,
                created_at, updated_at
         FROM project_images
         WHERE project_id = ?
         ORDER BY is_featured DESC, display_order ASC, id ASC'
    );
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = formatAdminProjectImage($row);
    }
    $stmt->close();
    return $items;
}

function formatAdminProjectHomeImage(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'project_id' => (int) $row['project_id'],
        'file_path' => (string) $row['file_path'],
        'url' => publicAssetUrl((string) $row['file_path']),
        'original_filename' => $row['original_filename'] ?: null,
        'mime_type' => $row['mime_type'] ?: null,
        'file_size' => (int) ($row['file_size'] ?? 0),
        'alt_text_en' => $row['alt_text_en'] ?: null,
        'alt_text_fr' => $row['alt_text_fr'] ?: null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function adminProjectHomeImage(mysqli $conn, int $projectId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, project_id, file_path, original_filename, mime_type, file_size,
                alt_text_en, alt_text_fr, created_at, updated_at
         FROM project_home_images
         WHERE project_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ? formatAdminProjectHomeImage($row) : null;
}

function projectDetailRowById(mysqli $conn, int $projectId): ?array
{
    $stmt = $conn->prepare(
        'SELECT p.*, d.division_key, d.slug AS division_slug, d.name_en AS division_name_en,
                d.name_fr AS division_name_fr, d.status AS division_status,
                phi.id AS homepage_image_id, phi.file_path AS homepage_image_path,
                phi.original_filename AS homepage_image_original_filename,
                phi.mime_type AS homepage_image_mime_type, phi.file_size AS homepage_image_file_size,
                phi.alt_text_en AS homepage_image_alt_en, phi.alt_text_fr AS homepage_image_alt_fr,
                phi.created_at AS homepage_image_created_at, phi.updated_at AS homepage_image_updated_at
         FROM projects p
         INNER JOIN divisions d ON d.id = p.division_id
         LEFT JOIN project_home_images phi ON phi.project_id = p.id
         WHERE p.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function ensureProjectExists(mysqli $conn, int $projectId): array
{
    $row = projectDetailRowById($conn, $projectId);
    if (!$row) {
        jsonResponse(['status' => 'Failed', 'message' => 'Project not found.'], 404);
    }
    return $row;
}

function assertCanModifyProject(array $admin, array $project): void
{
    if ((string) ($admin['role'] ?? '') === 'editor' && (string) ($project['status'] ?? '') !== 'draft') {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Editors can only modify draft projects. Ask an Admin to unpublish this project first.',
        ], 403);
    }
}

function projectImageCount(mysqli $conn, int $projectId): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM project_images WHERE project_id = ?');
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

function formatAdminProjectRow(array $row): array
{
    $payload = [
        'id' => (int) $row['id'],
        'division' => [
            'id' => (int) $row['division_id'],
            'key' => (string) ($row['division_key'] ?? ''),
            'slug' => (string) ($row['division_slug'] ?? ''),
            'name_en' => (string) ($row['division_name_en'] ?? ''),
            'name_fr' => (string) ($row['division_name_fr'] ?? ''),
            'status' => (string) ($row['division_status'] ?? ''),
        ],
        'slug' => (string) $row['slug'],
        'title_en' => (string) $row['title_en'],
        'title_fr' => (string) ($row['title_fr'] ?? ''),
        'summary_en' => $row['summary_en'] ?: null,
        'summary_fr' => $row['summary_fr'] ?: null,
        'description_en' => $row['description_en'] ?: null,
        'description_fr' => $row['description_fr'] ?: null,
        'location_en' => $row['location_en'] ?: null,
        'location_fr' => $row['location_fr'] ?: null,
        'work_done_en' => $row['work_done_en'] ?: null,
        'work_done_fr' => $row['work_done_fr'] ?: null,
        'project_year' => $row['project_year'] ?: null,
        'seo_title_en' => $row['seo_title_en'] ?: null,
        'seo_title_fr' => $row['seo_title_fr'] ?: null,
        'seo_description_en' => $row['seo_description_en'] ?: null,
        'seo_description_fr' => $row['seo_description_fr'] ?: null,
        'featured' => (bool) $row['featured'],
        'status' => (string) $row['status'],
        'display_order' => (int) $row['display_order'],
        'published_at' => $row['published_at'] ?: null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];

    foreach (['image_count'] as $countField) {
        if (array_key_exists($countField, $row)) {
            $payload[$countField] = (int) $row[$countField];
        }
    }

    if (!empty($row['featured_image_id'])) {
        $payload['featured_image'] = [
            'id' => (int) $row['featured_image_id'],
            'url' => publicAssetUrl((string) $row['featured_image_path']),
            'alt_text_en' => (string) ($row['featured_image_alt_en'] ?? ''),
            'alt_text_fr' => (string) ($row['featured_image_alt_fr'] ?? ''),
        ];
    } else {
        $payload['featured_image'] = null;
    }

    if (!empty($row['homepage_image_id'])) {
        $payload['homepage_image'] = [
            'id' => (int) $row['homepage_image_id'],
            'project_id' => (int) $row['id'],
            'file_path' => (string) $row['homepage_image_path'],
            'url' => publicAssetUrl((string) $row['homepage_image_path']),
            'original_filename' => $row['homepage_image_original_filename'] ?: null,
            'mime_type' => $row['homepage_image_mime_type'] ?: null,
            'file_size' => (int) ($row['homepage_image_file_size'] ?? 0),
            'alt_text_en' => $row['homepage_image_alt_en'] ?: null,
            'alt_text_fr' => $row['homepage_image_alt_fr'] ?: null,
            'created_at' => $row['homepage_image_created_at'] ?? null,
            'updated_at' => $row['homepage_image_updated_at'] ?? null,
        ];
    } else {
        $payload['homepage_image'] = null;
    }

    return $payload;
}

function projectPublishReadiness(mysqli $conn, array $project): array
{
    $issues = [];
    if (trim((string) ($project['title_en'] ?? '')) === '') {
        $issues[] = 'English title is required.';
    }
    if (trim((string) ($project['title_fr'] ?? '')) === '') {
        $issues[] = 'French title is required.';
    }
    if ((string) ($project['division_status'] ?? '') !== 'active') {
        $issues[] = 'The assigned division must be active.';
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) AS featured_total
         FROM project_images WHERE project_id = ?'
    );
    $projectId = (int) $project['id'];
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ((int) ($counts['total'] ?? 0) < 1) {
        $issues[] = 'Add at least one project image.';
    } elseif ((int) ($counts['featured_total'] ?? 0) !== 1) {
        $issues[] = 'Select exactly one featured project image.';
    }

    return $issues;
}
