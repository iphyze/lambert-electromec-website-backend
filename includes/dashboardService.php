<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function dashboardStatusCounts(mysqli $conn, string $table, array $allowedStatuses): array
{
    $allowedTables = ['projects', 'media_items', 'contact_submissions', 'divisions', 'media_categories'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported dashboard table.');
    }

    $counts = array_fill_keys($allowedStatuses, 0);
    $result = $conn->query("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status");
    while ($row = $result->fetch_assoc()) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) $row['total'];
        }
    }

    return $counts;
}

function dashboardRecentActivity(mysqli $conn, int $limit, bool $includeSensitive): array
{
    $limit = max(1, min(20, $limit));
    $where = $includeSensitive
        ? '1=1'
        : "(al.entity_type IN ('project', 'project_image', 'division', 'media_item', 'media_category') OR al.action LIKE 'auth.%')";

    $sql = "SELECT al.id, al.action, al.entity_type, al.entity_id, al.metadata, al.created_at,
                   au.id AS admin_id, au.first_name, au.last_name, au.email, au.role
            FROM audit_logs al
            LEFT JOIN admin_users au ON au.id = al.admin_user_id
            WHERE {$where}
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $metadata = null;
        if (!empty($row['metadata'])) {
            $decoded = json_decode((string) $row['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        $items[] = [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'entity_type' => $row['entity_type'] ?: null,
            'entity_id' => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
            'metadata' => $metadata,
            'created_at' => (string) $row['created_at'],
            'admin' => $row['admin_id'] === null ? null : [
                'id' => (int) $row['admin_id'],
                'name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
            ],
        ];
    }
    $stmt->close();

    return $items;
}

function dashboardAdminUsers(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT role, status, COUNT(*) AS total
         FROM admin_users
         GROUP BY role, status"
    );

    $summary = [
        'total' => 0,
        'active' => 0,
        'by_role' => ['super_admin' => 0, 'admin' => 0, 'editor' => 0],
    ];

    while ($row = $result->fetch_assoc()) {
        $count = (int) $row['total'];
        $summary['total'] += $count;
        if ((string) $row['status'] === 'active') {
            $summary['active'] += $count;
        }

        $role = (string) $row['role'];
        if (array_key_exists($role, $summary['by_role'])) {
            $summary['by_role'][$role] += $count;
        }
    }

    return $summary;
}
