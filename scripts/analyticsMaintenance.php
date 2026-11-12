<?php

declare(strict_types=1);

// Daily website-statistics maintenance (run from cron, e.g. 02:15 every day):
//   15 2 * * * php /path/to/api/scripts/analyticsMaintenance.php >> /path/to/logs/analytics.log 2>&1
// Finalises daily totals for finished days and deletes detailed rows older
// than the retention period set in System Settings. Daily totals are kept.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/connection.php';
require_once dirname(__DIR__) . '/includes/analyticsService.php';

try {
    $result = analyticsMaintenance($conn);
    echo '[' . date('c') . '] Website statistics maintenance complete. Retention: '
        . $result['retention_days'] . ' days. Raw rows purged: ' . $result['purged_rows'] . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Website statistics maintenance failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
