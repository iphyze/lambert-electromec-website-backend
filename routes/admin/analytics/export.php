<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/connection.php';
require_once __DIR__ . '/../../../includes/authMiddleware.php';
require_once __DIR__ . '/../../../includes/authService.php';
require_once __DIR__ . '/../../../includes/analyticsService.php';

requireMethod('GET');
$admin = requireAdministrator($conn);

try {
    [$from, $to] = analyticsValidatedRange($_GET['from'] ?? null, $_GET['to'] ?? null);
} catch (InvalidArgumentException $error) {
    jsonResponse(['status' => 'Failed', 'message' => $error->getMessage()], 422);
}

$report = (string) ($_GET['report'] ?? 'daily');
if (!in_array($report, ['pages', 'sources', 'events', 'daily'], true)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Unsupported report.'], 422);
}

[$header, $rows] = analyticsExportRows($conn, $report, $from, $to);
recordAuditLog($conn, (int) $admin['id'], 'analytics.export', 'analytics', null, [
    'report' => $report,
    'from' => $from,
    'to' => $to,
    'rows' => count($rows),
]);

$filename = sprintf('lambert-website-%s-%s-to-%s.csv', $report, $from, $to);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Access-Control-Expose-Headers: Content-Disposition');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
$safe = static function (mixed $value): mixed {
    // Prevent spreadsheet formula injection from page paths or labels.
    return is_string($value) && preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
};
fputcsv($out, $header, ',', '"', '\\');
foreach ($rows as $row) {
    fputcsv($out, array_map($safe, $row), ',', '"', '\\');
}
fclose($out);
exit;
