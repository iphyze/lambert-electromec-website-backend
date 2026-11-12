<?php

declare(strict_types=1);

// Website statistics checks.
//   php scripts/testAnalytics.php            offline checks
//   php scripts/testAnalytics.php --with-db  also records sample data in a
//                                             transaction, rolls it up, checks
//                                             the report and rolls everything back.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/analyticsService.php';

$failures = 0;
$check = static function (bool $condition, string $description) use (&$failures): void {
    echo ($condition ? '[OK] ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
};

try {
    // Paths
    $check(analyticsNormalizePath('/About?utm_source=x#team') === '/about/', 'Query string and fragment are removed.');
    $check(analyticsNormalizePath('/fr/projects/Eko-Tower') === '/fr/projects/eko-tower/', 'Paths are lowercased with a trailing slash.');
    $check(analyticsNormalizePath('/admin/settings') === null && analyticsNormalizePath('/api/health') === null, 'Admin and API URLs are not pages.');
    $check(analyticsNormalizePath('/uploads/file.pdf') === null && analyticsNormalizePath('/brochure.pdf') === null, 'File downloads are not pages.');
    $check(analyticsNormalizePath('https://evil.example/') === null, 'Absolute URLs are rejected.');

    // Sources
    $site = ['lambertelectromec.com'];
    $check(analyticsSourceType(null, [], $site) === 'direct', 'No referrer is direct.');
    $check(analyticsSourceType('google.com', [], $site) === 'search' && analyticsSourceType('bing.com', [], $site) === 'search', 'Search engines are search.');
    $check(analyticsSourceType('linkedin.com', [], $site) === 'social' && analyticsSourceType('t.co', [], $site) === 'social', 'Social networks are social.');
    $check(analyticsSourceType('example.org', [], $site) === 'referral', 'Other sites are referrals.');
    $check(analyticsSourceType('lambertelectromec.com', [], $site) === 'internal', 'The site itself is internal.');
    $check(analyticsSourceType('google.com', ['source' => 'newsletter'], $site) === 'campaign', 'UTM tags make a campaign.');
    $check(analyticsReferrerHost('https://www.Google.com/search?q=lambert') === 'google.com', 'Referrer host is cleaned and private parts are dropped.');

    // User agents
    $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    $edge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0';
    $android = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0 Mobile Safari/537.36';
    $check(analyticsParseUserAgent($iphone) === ['device' => 'mobile', 'browser' => 'Safari', 'os' => 'iOS'], 'iPhone Safari is recognised.');
    $check(analyticsParseUserAgent($edge) === ['device' => 'desktop', 'browser' => 'Edge', 'os' => 'Windows'], 'Windows Edge is recognised.');
    $check(analyticsParseUserAgent($android) === ['device' => 'mobile', 'browser' => 'Samsung Internet', 'os' => 'Android'], 'Samsung Internet on Android is recognised.');
    foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.4.0', 'python-requests/2.31', 'Mozilla/5.0 HeadlessChrome/120', ''] as $bot) {
        $check(analyticsIsBot($bot), 'Bot is ignored: ' . ($bot === '' ? '(empty user agent)' : $bot));
    }
    $check(!analyticsIsBot($iphone) && !analyticsIsBot($edge), 'Real browsers are not treated as bots.');

    // Event labels: only short, non-personal values survive.
    $check(analyticsCleanLabel('phone_click', 'ng') === 'NG' && analyticsCleanLabel('phone_click', '+2349139382769') === null, 'Phone clicks keep the office code, never the number.');
    $check(analyticsCleanLabel('media_downloaded', 'LEM-PRC-01-PO-001-A') === 'LEM-PRC-01-PO-001-A', 'Media downloads keep the document reference.');
    $check(analyticsCleanLabel('assistant_question', 'What is your address?') === null, 'Assistant questions never store text.');
    $check(analyticsCleanLabel('contact_submitted', 'ada@example.com') === null, 'Form events never store personal data.');
    $check(analyticsCleanLabel('outbound_click', 'www.LinkedIn.com') === 'linkedin.com', 'Outbound clicks keep only the domain.');
    $check(!in_array('form_values', analyticsEventNames(), true), 'Unknown event names are not accepted.');

    // DNT / GPC
    $_SERVER['HTTP_DNT'] = '1';
    $check(analyticsDoNotTrackRequested(), 'Do Not Track is detected.');
    unset($_SERVER['HTTP_DNT']);
    $_SERVER['HTTP_SEC_GPC'] = '1';
    $check(analyticsDoNotTrackRequested(), 'Global Privacy Control is detected.');
    unset($_SERVER['HTTP_SEC_GPC']);
    $check(!analyticsDoNotTrackRequested(), 'Normal requests are counted.');

    // Range validation
    [$from, $to] = analyticsValidatedRange(null, null);
    $check((int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%a') === 29, 'Default period is the last 30 days.');
    try {
        analyticsValidatedRange('2020-01-01', '2022-01-01');
        $check(false, 'Periods longer than 366 days are rejected.');
    } catch (InvalidArgumentException) {
        $check(true, 'Periods longer than 366 days are rejected.');
    }

    if (in_array('--with-db', $argv, true)) {
        require_once $root . '/includes/connection.php';
        /** @var mysqli $conn */
        $conn->begin_transaction();
        $GLOBALS['lambert_analytics_outer_transaction'] = true;
        $_ENV['ANALYTICS_ALLOWED_HOSTS'] = 'lambertelectromec.com';

        // Hash rotation
        $hashA = analyticsVisitorHash($conn, 'lambertelectromec.com', '203.0.113.5', $iphone);
        $hashB = analyticsVisitorHash($conn, 'lambertelectromec.com', '203.0.113.5', $iphone);
        $check($hashA === $hashB && strlen($hashA) === 64, 'The same visitor gets the same hash within a day.');
        $check(!str_contains($hashA, '203.0.113.5'), 'The hash does not contain the IP address.');
        $saltToday = analyticsDailySalt($conn);
        $check(strlen($saltToday) === 32, 'A random daily salt exists.');

        $day = analyticsNow()->modify('-3 days')->setTime(10, 0);
        $date = $day->format('Y-m-d');
        $iphoneClient = analyticsParseUserAgent($iphone) + ['country' => 'NG'];
        $edgeClient = analyticsParseUserAgent($edge) + ['country' => null];
        $visitorOne = str_repeat('a', 64);
        $visitorTwo = str_repeat('b', 64);
        $pv = static fn (int $n): string => str_pad(dechex($n), 32, '0', STR_PAD_LEFT);

        // Visitor one: from Google, 3 pages within 30 minutes, then back 2 hours later (new visit).
        analyticsRecordPageview($conn, $visitorOne, $pv(1), ['path' => '/', 'locale' => 'en', 'referrer' => 'https://www.google.com/', 'utm' => []], $iphoneClient, $day);
        analyticsRecordPageview($conn, $visitorOne, $pv(2), ['path' => '/projects', 'locale' => 'en', 'referrer' => 'https://lambertelectromec.com/', 'utm' => []], $iphoneClient, $day->modify('+5 minutes'));
        analyticsRecordPageview($conn, $visitorOne, $pv(3), ['path' => '/contact', 'locale' => 'en', 'referrer' => '', 'utm' => []], $iphoneClient, $day->modify('+20 minutes'));
        $duplicate = analyticsRecordPageview($conn, $visitorOne, $pv(3), ['path' => '/contact', 'locale' => 'en', 'referrer' => '', 'utm' => []], $iphoneClient, $day->modify('+20 minutes'));
        $check($duplicate === false, 'The same page view is never counted twice.');
        analyticsRecordEngagement($conn, $visitorOne, $pv(1), 45000, 80);
        analyticsRecordEngagement($conn, $visitorOne, $pv(1), 20000, 30);
        analyticsRecordEngagement($conn, $visitorOne, $pv(3), 99999999, 150);
        analyticsRecordEvent($conn, $visitorOne, $pv(3), 'contact_submitted', null, $day->modify('+22 minutes'));
        analyticsRecordEvent($conn, $visitorOne, $pv(3), 'phone_click', 'gh', $day->modify('+23 minutes'));
        analyticsRecordPageview($conn, $visitorOne, $pv(4), ['path' => '/fr/', 'locale' => 'fr', 'referrer' => '', 'utm' => []], $iphoneClient, $day->modify('+2 hours'));

        // Visitor two: LinkedIn campaign, one quick page (a bounce), a download.
        analyticsRecordPageview($conn, $visitorTwo, $pv(5), ['path' => '/media-center', 'locale' => 'en', 'referrer' => 'https://www.linkedin.com/', 'utm' => ['source' => 'linkedin', 'campaign' => 'Q4 Launch']], $edgeClient, $day->modify('+1 hour'));
        analyticsRecordEngagement($conn, $visitorTwo, $pv(5), 4000, 20);
        analyticsRecordEvent($conn, $visitorTwo, $pv(5), 'media_downloaded', 'LEM-PRC-01-PO-001-A', $day->modify('+61 minutes'));
        analyticsRecordEvent($conn, $visitorTwo, $pv(5), 'assistant_question', 'tell me about your address', $day->modify('+62 minutes'));

        $engaged = $conn->query("SELECT engaged_ms, max_scroll_pct FROM analytics_pageviews WHERE page_view_id = '" . $pv(1) . "'")->fetch_assoc();
        $check((int) $engaged['engaged_ms'] === 45000 && (int) $engaged['max_scroll_pct'] === 80, 'Engagement only increases (a smaller later value is ignored).');
        $capped = $conn->query("SELECT engaged_ms, max_scroll_pct FROM analytics_pageviews WHERE page_view_id = '" . $pv(3) . "'")->fetch_assoc();
        $check((int) $capped['engaged_ms'] === ANALYTICS_MAX_ENGAGED_MS && (int) $capped['max_scroll_pct'] === 100, 'Engagement is capped at 30 minutes and 100% scroll.');
        $label = $conn->query("SELECT label FROM analytics_events WHERE event_name = 'assistant_question' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $check($label['label'] === null, 'Assistant question text is never stored.');

        analyticsRollUpDay($conn, $date);
        $report = analyticsReport($conn, $date, $date, false);
        $t = $report['totals'];
        $check($t['visits'] === 3, 'Visits use the 30-minute rule (3 visits).');
        $check($t['visitors'] === 2, 'Unique visitors are counted (2).');
        $check($t['pageviews'] === 5, 'Page views are counted (5).');
        $check(abs($t['bounce_rate'] - 66.7) < 0.1, 'Bounce rate counts one-page visits under 10 seconds (2 of 3).');
        $check($t['enquiries'] === 1, 'Contact enquiries are counted.');
        $sources = array_column($report['sources'], 'visits', 'value');
        $check(($sources['search'] ?? 0) === 1 && ($sources['campaign'] ?? 0) === 1 && ($sources['direct'] ?? 0) === 1, 'Visits are attributed to search, campaign and direct.');
        $pages = array_column($report['top_pages'], 'pageviews', 'value');
        $check(($pages['/projects/'] ?? 0) === 1 && ($pages['/contact/'] ?? 0) === 1, 'Top pages are listed.');
        $labels = array_column($report['event_labels'], 'label', 'event');
        $check(($labels['media_downloaded'] ?? '') === 'LEM-PRC-01-PO-001-A' && ($labels['phone_click'] ?? '') === 'GH', 'Action labels show document references and office codes.');
        $check(count($report['timeseries']['points']) === 24 && $report['timeseries']['granularity'] === 'hour', 'A single day is shown per hour.');
        $countries = array_column($report['countries'], 'visits', 'value');
        $check(($countries['NG'] ?? 0) === 2 && ($countries['--'] ?? 0) === 1, 'Countries are counted, unknown shown separately.');

        // Daily totals survive the raw-data purge.
        $_ENV['ANALYTICS_RAW_RETENTION_DAYS'] = '30';
        $conn->query("UPDATE analytics_pageviews SET occurred_at = DATE_SUB(occurred_at, INTERVAL 400 DAY) WHERE visitor_hash IN ('{$visitorOne}', '{$visitorTwo}')");
        $conn->query("UPDATE analytics_visits SET started_at = DATE_SUB(started_at, INTERVAL 400 DAY), last_seen_at = DATE_SUB(last_seen_at, INTERVAL 400 DAY) WHERE visitor_hash IN ('{$visitorOne}', '{$visitorTwo}')");
        $conn->query("UPDATE analytics_events SET occurred_at = DATE_SUB(occurred_at, INTERVAL 400 DAY) WHERE visitor_hash IN ('{$visitorOne}', '{$visitorTwo}')");
        $oldDate = (new DateTimeImmutable($date))->modify('-400 days')->format('Y-m-d');
        analyticsMaintenance($conn);
        $remaining = (int) $conn->query("SELECT COUNT(*) AS c FROM analytics_pageviews WHERE visitor_hash IN ('{$visitorOne}', '{$visitorTwo}')")->fetch_assoc()['c'];
        $kept = analyticsTotals($conn, $oldDate, $oldDate);
        $check($remaining === 0 && $kept['pageviews'] === 5 && $kept['visits'] === 3, 'Old detailed rows are purged while the daily totals remain.');

        $conn->rollback();
        $GLOBALS['lambert_analytics_outer_transaction'] = false;
        $leftover = (int) $conn->query('SELECT COUNT(*) AS c FROM analytics_pageviews WHERE visitor_hash IN (\'' . $visitorOne . '\', \'' . $visitorTwo . '\')')->fetch_assoc()['c'];
        $check($leftover === 0, 'Test data was rolled back.');
    }
} catch (Throwable $error) {
    $check(false, 'Unhandled exception: ' . $error->getMessage() . ' @ ' . basename($error->getFile()) . ':' . $error->getLine());
}

if ($failures > 0) {
    echo PHP_EOL . $failures . ' website statistics test(s) failed.' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'Website statistics tests passed.' . PHP_EOL;
exit(0);
