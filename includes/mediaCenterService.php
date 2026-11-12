<?php

declare(strict_types=1);

require_once __DIR__ . '/contentService.php';

function mediaLocaleColumns(string $lang): array
{
    if ($lang === 'fr') {
        return [
            'title' => "COALESCE(NULLIF(m.title_fr, ''), m.title_en)",
            'description' => "COALESCE(NULLIF(m.description_fr, ''), m.description_en)",
            'category_name' => "COALESCE(NULLIF(c.name_fr, ''), c.name_en)",
        ];
    }

    return [
        'title' => 'm.title_en',
        'description' => 'm.description_en',
        'category_name' => 'c.name_en',
    ];
}

function publicMediaCategories(mysqli $conn, string $lang): array
{
    $nameColumn = $lang === 'fr' ? "COALESCE(NULLIF(name_fr, ''), name_en)" : 'name_en';
    $result = $conn->query(
        "SELECT id, slug, {$nameColumn} AS name, display_order
         FROM media_categories
         WHERE status = 'active'
         ORDER BY display_order ASC, id ASC"
    );

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'display_order' => (int) $row['display_order'],
        ];
    }
    return $items;
}

function publicMediaItems(mysqli $conn, string $lang): array
{
    [$page, $limit, $offset] = paginationInput(12, 50);
    $columns = mediaLocaleColumns($lang);

    $category = trim((string) ($_GET['category'] ?? ''));
    $search = trim((string) ($_GET['search'] ?? ''));
    $featuredOnly = booleanFlag($_GET['featured'] ?? null, false);

    if (mb_strlen($category) > 120 || mb_strlen($search) > 180) {
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid filter value.'], 422);
    }

    $conditions = ["m.status = 'published'", '(m.published_at IS NULL OR m.published_at <= NOW())'];
    $types = '';
    $values = [];

    if ($category !== '') {
        $conditions[] = 'c.slug = ?';
        $types .= 's';
        $values[] = $category;
    }

    if ($featuredOnly) {
        $conditions[] = 'm.featured = 1';
    }

    if ($search !== '') {
        $needle = '%' . $search . '%';
        $conditions[] = "({$columns['title']} LIKE ? OR m.reference LIKE ? OR {$columns['description']} LIKE ?)";
        $types .= 'sss';
        array_push($values, $needle, $needle, $needle);
    }

    $where = implode(' AND ', $conditions);
    $countSql = "SELECT COUNT(*) AS total
                 FROM media_items m
                 INNER JOIN media_categories c ON c.id = m.category_id
                 WHERE {$where}";
    $countStmt = $conn->prepare($countSql);
    executePrepared($countStmt, $types, $values);
    $total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $sql = "SELECT m.id, m.slug, m.reference, {$columns['title']} AS title,
                   {$columns['description']} AS description, m.featured, m.published_at,
                   m.file_path, m.original_filename, m.mime_type, m.file_size,
                   m.cover_image_path, m.cover_image_original_filename, m.cover_image_mime_type,
                   m.cover_image_size, m.cover_image_width, m.cover_image_height,
                   c.id AS category_id, c.slug AS category_slug, {$columns['category_name']} AS category_name
            FROM media_items m
            INNER JOIN media_categories c ON c.id = m.category_id
            WHERE {$where}
            ORDER BY m.featured DESC, COALESCE(m.published_at, m.created_at) DESC, m.display_order ASC, m.id DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    executePrepared($stmt, $types . 'ii', array_merge($values, [$limit, $offset]));
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = formatPublicMediaRow($row);
    }
    $stmt->close();

    return [
        'items' => $items,
        'pagination' => paginationPayload($page, $limit, $total),
    ];
}

function formatPublicMediaRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'slug' => (string) $row['slug'],
        'reference' => (string) ($row['reference'] ?? ''),
        'title' => (string) $row['title'],
        'description' => (string) ($row['description'] ?? ''),
        'featured' => (bool) $row['featured'],
        'published_at' => $row['published_at'] ?: null,
        'category' => [
            'id' => (int) $row['category_id'],
            'slug' => (string) $row['category_slug'],
            'name' => (string) $row['category_name'],
        ],
        'file' => [
            'url' => publicAssetUrl((string) $row['file_path']),
            'name' => (string) ($row['original_filename'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'extension' => mediaFileExtension((string) ($row['original_filename'] ?? ''), (string) ($row['mime_type'] ?? '')),
            'size_bytes' => (int) ($row['file_size'] ?? 0),
        ],
        'cover_image' => !empty($row['cover_image_path']) ? [
            'url' => publicAssetUrl((string) $row['cover_image_path']),
            'name' => (string) ($row['cover_image_original_filename'] ?? ''),
            'mime_type' => (string) ($row['cover_image_mime_type'] ?? ''),
            'size_bytes' => (int) ($row['cover_image_size'] ?? 0),
            'width' => (int) ($row['cover_image_width'] ?? 0),
            'height' => (int) ($row['cover_image_height'] ?? 0),
        ] : null,
    ];
}

function publicMediaBySlug(mysqli $conn, string $slug, string $lang): ?array
{
    $slug = trim($slug);
    if ($slug === '' || mb_strlen($slug) > 220) {
        return null;
    }

    $columns = mediaLocaleColumns($lang);
    $sql = "SELECT m.id, m.slug, m.reference, {$columns['title']} AS title,
                   {$columns['description']} AS description, m.featured, m.published_at,
                   m.file_path, m.original_filename, m.mime_type, m.file_size,
                   m.cover_image_path, m.cover_image_original_filename, m.cover_image_mime_type,
                   m.cover_image_size, m.cover_image_width, m.cover_image_height,
                   c.id AS category_id, c.slug AS category_slug, {$columns['category_name']} AS category_name
            FROM media_items m
            INNER JOIN media_categories c ON c.id = m.category_id
            WHERE m.slug = ? AND m.status = 'published'
              AND (m.published_at IS NULL OR m.published_at <= NOW())
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? formatPublicMediaRow($row) : null;
}

function uniqueMediaItemSlug(mysqli $conn, string $requested, string $title, ?int $excludeId = null): string
{
    $base = substr(slugifyContent($requested !== '' ? $requested : $title, 'media-item'), 0, 220);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $conn->prepare('SELECT id FROM media_items WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $candidate, $excludeId);
        } else {
            $stmt = $conn->prepare('SELECT id FROM media_items WHERE slug = ? LIMIT 1');
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

function uniqueMediaCategorySlug(mysqli $conn, string $requested, string $name, ?int $excludeId = null): string
{
    $base = substr(slugifyContent($requested !== '' ? $requested : $name, 'media-category'), 0, 120);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $conn->prepare('SELECT id FROM media_categories WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $candidate, $excludeId);
        } else {
            $stmt = $conn->prepare('SELECT id FROM media_categories WHERE slug = ? LIMIT 1');
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

function mediaCategoryById(mysqli $conn, int $categoryId, bool $activeOnly = false): ?array
{
    $sql = 'SELECT id, slug, name_en, name_fr, status, display_order, created_at, updated_at
            FROM media_categories WHERE id = ?';
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $categoryId);
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

function mediaItemDetailById(mysqli $conn, int $itemId): ?array
{
    $stmt = $conn->prepare(
        'SELECT m.*, c.slug AS category_slug, c.name_en AS category_name_en,
                c.name_fr AS category_name_fr, c.status AS category_status
         FROM media_items m
         INNER JOIN media_categories c ON c.id = m.category_id
         WHERE m.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function ensureMediaItemExists(mysqli $conn, int $itemId): array
{
    $row = mediaItemDetailById($conn, $itemId);
    if (!$row) {
        jsonResponse(['status' => 'Failed', 'message' => 'Media Center item not found.'], 404);
    }
    return $row;
}

function assertCanModifyMediaItem(array $admin, array $item): void
{
    if ((string) ($admin['role'] ?? '') === 'editor' && (string) ($item['status'] ?? '') !== 'draft') {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Editors can only modify draft Media Center items. Ask an Admin to unpublish this item first.',
        ], 403);
    }
}

function mediaFileExtension(?string $filename, ?string $mimeType = null): string
{
    $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    if ($extension !== '') {
        return $extension;
    }

    return match (strtolower(trim((string) $mimeType))) {
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/csv' => 'csv',
        'text/plain' => 'txt',
        default => '',
    };
}

function formatAdminMediaItem(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'category' => [
            'id' => (int) $row['category_id'],
            'slug' => (string) ($row['category_slug'] ?? ''),
            'name_en' => (string) ($row['category_name_en'] ?? ''),
            'name_fr' => (string) ($row['category_name_fr'] ?? ''),
            'status' => (string) ($row['category_status'] ?? ''),
        ],
        'slug' => (string) $row['slug'],
        'reference' => $row['reference'] ?: null,
        'title_en' => (string) $row['title_en'],
        'title_fr' => (string) ($row['title_fr'] ?? ''),
        'description_en' => $row['description_en'] ?: null,
        'description_fr' => $row['description_fr'] ?: null,
        'file' => [
            'file_path' => (string) $row['file_path'],
            'url' => publicAssetUrl((string) $row['file_path']),
            'name' => (string) $row['original_filename'],
            'mime_type' => (string) $row['mime_type'],
            'extension' => mediaFileExtension((string) $row['original_filename'], (string) $row['mime_type']),
            'size_bytes' => (int) $row['file_size'],
        ],
        'cover_image' => !empty($row['cover_image_path']) ? [
            'file_path' => (string) $row['cover_image_path'],
            'url' => publicAssetUrl((string) $row['cover_image_path']),
            'name' => (string) ($row['cover_image_original_filename'] ?? ''),
            'mime_type' => (string) ($row['cover_image_mime_type'] ?? ''),
            'size_bytes' => (int) ($row['cover_image_size'] ?? 0),
            'width' => (int) ($row['cover_image_width'] ?? 0),
            'height' => (int) ($row['cover_image_height'] ?? 0),
        ] : null,
        'featured' => (bool) $row['featured'],
        'status' => (string) $row['status'],
        'display_order' => (int) $row['display_order'],
        'published_at' => $row['published_at'] ?: null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function mediaItemPublishReadiness(array $item): array
{
    $issues = [];
    if (trim((string) ($item['title_en'] ?? '')) === '') {
        $issues[] = 'English title is required.';
    }
    if (trim((string) ($item['title_fr'] ?? '')) === '') {
        $issues[] = 'French title is required.';
    }
    if ((string) ($item['category_status'] ?? '') !== 'active') {
        $issues[] = 'The assigned Media Center category must be active.';
    }
    if (trim((string) ($item['file_path'] ?? '')) === '') {
        $issues[] = 'A document is required.';
    }
    return $issues;
}
