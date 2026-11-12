<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/mediaCenterService.php';

requireMethod('POST');
$admin = requireAdministrator($conn);
requireCsrfToken();
applyNoStoreHeaders();
$body = requestJsonBody();

$itemId = positiveInt($body['id'] ?? null, 'Media Center item ID');
$action = strtolower(contentString($body['action'] ?? null, 30, true, 'Action'));
$existing = ensureMediaItemExists($conn, $itemId);
$adminId = (int) $admin['id'];

switch ($action) {
    case 'publish':
        $issues = mediaItemPublishReadiness($existing);
        if ($issues !== []) {
            jsonResponse([
                'status' => 'Failed',
                'message' => 'Media Center item is not ready to publish.',
                'errors' => $issues,
            ], 409);
        }
        $publishedAt = array_key_exists('published_at', $body)
            ? normalizeDateTime($body['published_at'], 'Publish date')
            : ($existing['published_at'] ?: (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s'));
        if ($publishedAt === null) {
            $publishedAt = (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s');
        }
        $stmt = $conn->prepare("UPDATE media_items SET status = 'published', published_at = ?, updated_by = ? WHERE id = ?");
        $stmt->bind_param('sii', $publishedAt, $adminId, $itemId);
        $isFuturePublication = new DateTimeImmutable($publishedAt, appTimeZone()) > new DateTimeImmutable('now', appTimeZone());
        $message = $isFuturePublication ? 'Media Center item scheduled for publication.' : 'Media Center item published successfully.';
        break;

    case 'unpublish':
        $stmt = $conn->prepare("UPDATE media_items SET status = 'draft', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $itemId);
        $message = 'Media Center item moved back to draft.';
        break;

    case 'archive':
        $stmt = $conn->prepare("UPDATE media_items SET status = 'archived', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $itemId);
        $message = 'Media Center item archived successfully.';
        break;

    case 'restore':
        $stmt = $conn->prepare("UPDATE media_items SET status = 'draft', updated_by = ? WHERE id = ?");
        $stmt->bind_param('ii', $adminId, $itemId);
        $message = 'Media Center item restored as a draft.';
        break;

    default:
        jsonResponse(['status' => 'Failed', 'message' => 'Unsupported Media Center action.'], 422);
}

$stmt->execute();
$stmt->close();
recordAuditLog($conn, $adminId, 'media_item.' . $action, 'media_item', $itemId, [
    'previous_status' => $existing['status'],
]);
jsonResponse([
    'status' => 'Success',
    'message' => $message,
    'data' => formatAdminMediaItem(ensureMediaItemExists($conn, $itemId)),
]);
