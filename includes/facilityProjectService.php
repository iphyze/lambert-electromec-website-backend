<?php

declare(strict_types=1);

require_once __DIR__ . '/contentService.php';

function facilityProjectLocaleColumns(string $lang): array
{
    if ($lang === 'fr') {
        return [
            'title' => "COALESCE(NULLIF(title_fr, ''), title_en)",
            'description' => "COALESCE(NULLIF(description_fr, ''), description_en)",
            'work_done' => "COALESCE(NULLIF(work_done_fr, ''), work_done_en)",
        ];
    }

    return [
        'title' => 'title_en',
        'description' => 'description_en',
        'work_done' => 'work_done_en',
    ];
}

function publicFacilityProjects(mysqli $conn, string $lang): array
{
    $columns = facilityProjectLocaleColumns($lang);
    $sql = "SELECT id, slug, {$columns['title']} AS title,
                   {$columns['description']} AS description,
                   {$columns['work_done']} AS work_done,
                   image_path, display_order, published_at
            FROM facility_projects
            WHERE status = 'published'
              AND (published_at IS NULL OR published_at <= NOW())
            ORDER BY display_order ASC, COALESCE(published_at, created_at) DESC, id ASC";

    $result = $conn->query($sql);
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'description' => (string) ($row['description'] ?? ''),
            'work_done' => (string) ($row['work_done'] ?? ''),
            'image_url' => publicAssetUrl((string) ($row['image_path'] ?? '')),
            'display_order' => (int) $row['display_order'],
            'published_at' => $row['published_at'] ?: null,
        ];
    }
    return $items;
}

function uniqueFacilityProjectSlug(mysqli $conn, string $requested, string $title, ?int $excludeId = null): string
{
    $base = substr(slugifyContent($requested !== '' ? $requested : $title, 'facility-project'), 0, 220);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $conn->prepare('SELECT id FROM facility_projects WHERE slug = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $candidate, $excludeId);
        } else {
            $stmt = $conn->prepare('SELECT id FROM facility_projects WHERE slug = ? LIMIT 1');
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

function facilityProjectById(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM facility_projects WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function ensureFacilityProjectExists(mysqli $conn, int $id): array
{
    $row = facilityProjectById($conn, $id);
    if (!$row) {
        jsonResponse(['status' => 'Failed', 'message' => 'Facility Management project not found.'], 404);
    }
    return $row;
}

function assertCanModifyFacilityProject(array $admin, array $project): void
{
    if ((string) ($admin['role'] ?? '') === 'editor' && (string) ($project['status'] ?? '') !== 'draft') {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Editors can only modify draft Facility Management projects. Ask an Admin to unpublish this project first.',
        ], 403);
    }
}

function formatAdminFacilityProject(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'slug' => (string) $row['slug'],
        'title_en' => (string) $row['title_en'],
        'title_fr' => (string) ($row['title_fr'] ?? ''),
        'description_en' => $row['description_en'] ?: '',
        'description_fr' => $row['description_fr'] ?: '',
        'work_done_en' => $row['work_done_en'] ?: '',
        'work_done_fr' => $row['work_done_fr'] ?: '',
        'image_path' => $row['image_path'] ?: null,
        'image_url' => publicAssetUrl((string) ($row['image_path'] ?? '')),
        'image_original_filename' => $row['image_original_filename'] ?: null,
        'image_mime_type' => $row['image_mime_type'] ?: null,
        'image_size' => (int) ($row['image_size'] ?? 0),
        'status' => (string) $row['status'],
        'display_order' => (int) $row['display_order'],
        'published_at' => $row['published_at'] ?: null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function facilityProjectPublishReadiness(array $project): array
{
    $issues = [];
    if (trim((string) ($project['title_en'] ?? '')) === '') {
        $issues[] = 'English title is required.';
    }
    if (trim((string) ($project['title_fr'] ?? '')) === '') {
        $issues[] = 'French title is required.';
    }
    if (trim((string) ($project['image_path'] ?? '')) === '') {
        $issues[] = 'Add a project image.';
    }
    return $issues;
}
