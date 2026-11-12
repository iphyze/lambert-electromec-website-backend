<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contentService.php';
require_once __DIR__ . '/runtimeSettingsService.php';

/*
 * Lambert website statistics.
 *
 * Privacy model (no cookies, no browser storage, no stored IP addresses):
 * - visitor_hash = sha256(daily salt | site host | IP | user agent). The salt
 *   is random, changes every day (Africa/Lagos) and is deleted after one day,
 *   so a hash cannot be linked to another day or traced back to a person.
 * - A visit is the same visitor hash with no more than 30 minutes between
 *   page views.
 * - Do Not Track / Global Privacy Control are respected (setting, on by default).
 * - Bots and unknown hosts are ignored.
 * - Only non-personal event labels are accepted (document reference, office
 *   code, language, outbound domain).
 */

const ANALYTICS_VISIT_GAP_SECONDS = 1800;
const ANALYTICS_BOUNCE_ENGAGED_MS = 10000;
const ANALYTICS_MAX_ENGAGED_MS = 1800000;
const ANALYTICS_MAX_RANGE_DAYS = 366;

/* ------------------------------------------------------------------------ */
/* Settings                                                                  */
/* ------------------------------------------------------------------------ */

function analyticsEnabled(): bool
{
    return runtimeSettingBoolean('ANALYTICS_ENABLED', true);
}

function analyticsRespectDoNotTrack(): bool
{
    return runtimeSettingBoolean('ANALYTICS_RESPECT_DNT', true);
}

function analyticsRetentionDays(): int
{
    return max(30, min(730, (int) runtimeSettingValue('ANALYTICS_RAW_RETENTION_DAYS', 400)));
}

function analyticsRateLimitPerMinute(): int
{
    return max(20, min(1000, (int) runtimeSettingValue('ANALYTICS_COLLECT_RATE_LIMIT_PER_MINUTE', 120)));
}

/** Public-site hostnames that may send statistics (lowercase, without "www."). */
function analyticsAllowedHosts(): array
{
    $configured = trim((string) envValue('ANALYTICS_ALLOWED_HOSTS', ''));
    $hosts = [];

    if ($configured !== '') {
        foreach (explode(',', $configured) as $host) {
            $hosts[] = analyticsCleanHost($host);
        }
    } else {
        // Fall back to the public origins already trusted by the API.
        foreach (allowedOrigins() as $origin) {
            $hosts[] = analyticsCleanHost((string) parse_url($origin, PHP_URL_HOST));
        }
    }

    return array_values(array_unique(array_filter($hosts)));
}

function analyticsCleanHost(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function analyticsTrustCloudflareCountry(): bool
{
    return envBoolean('ANALYTICS_TRUST_CLOUDFLARE_COUNTRY', false);
}

function analyticsNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', appTimeZone());
}

function analyticsToday(): string
{
    return analyticsNow()->format('Y-m-d');
}

/* ------------------------------------------------------------------------ */
/* Anonymous visitor hash                                                    */
/* ------------------------------------------------------------------------ */

function analyticsDailySalt(mysqli $conn, ?string $date = null): string
{
    static $cache = [];
    $date ??= analyticsToday();
    if (isset($cache[$date])) {
        return $cache[$date];
    }

    $select = $conn->prepare('SELECT salt FROM analytics_salts WHERE salt_date = ?');
    $select->bind_param('s', $date);
    $select->execute();
    $row = $select->get_result()->fetch_assoc();
    $select->close();

    if (!$row) {
        $salt = random_bytes(32);
        $insert = $conn->prepare('INSERT IGNORE INTO analytics_salts (salt_date, salt) VALUES (?, ?)');
        $insert->bind_param('ss', $date, $salt);
        $insert->execute();
        $insert->close();

        // Another request may have created it first: always use the stored one.
        $select = $conn->prepare('SELECT salt FROM analytics_salts WHERE salt_date = ?');
        $select->bind_param('s', $date);
        $select->execute();
        $row = $select->get_result()->fetch_assoc();
        $select->close();

        // Old salts are deleted so earlier hashes can never be recomputed.
        $yesterday = (new DateTimeImmutable($date, appTimeZone()))->modify('-1 day')->format('Y-m-d');
        $delete = $conn->prepare('DELETE FROM analytics_salts WHERE salt_date < ?');
        $delete->bind_param('s', $yesterday);
        $delete->execute();
        $delete->close();
    }

    return $cache[$date] = (string) ($row['salt'] ?? '');
}

function analyticsVisitorHash(mysqli $conn, string $host, ?string $ip = null, ?string $userAgent = null): string
{
    return hash('sha256', implode('|', [
        analyticsDailySalt($conn),
        analyticsCleanHost($host),
        $ip ?? clientIpAddress(),
        $userAgent ?? requestUserAgent(),
    ]));
}

/* ------------------------------------------------------------------------ */
/* Request classification                                                    */
/* ------------------------------------------------------------------------ */

function analyticsIsBot(string $userAgent): bool
{
    $userAgent = trim($userAgent);
    if ($userAgent === '' || $userAgent === 'unknown') {
        return true;
    }

    return preg_match(
        '/bot|crawl|spider|slurp|bingpreview|mediapartners|headless|phantomjs|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|statuscake|curl|wget|python-requests|python-urllib|httpclient|okhttp|java\/|go-http-client|axios|node-fetch|libwww|scrapy|facebookexternalhit|embedly|whatsapp|preview|validator|feedfetcher|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baiduspider|duckduckbot|applebot/i',
        $userAgent
    ) === 1;
}

/** @return array{device: string, browser: string, os: string} */
function analyticsParseUserAgent(string $userAgent): array
{
    $ua = $userAgent;

    $device = 'desktop';
    if (preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua) === 1) {
        $device = 'tablet';
    } elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua) === 1) {
        $device = 'mobile';
    }

    $browser = match (true) {
        preg_match('/Edg(?:e|A|iOS)?\//', $ua) === 1 => 'Edge',
        preg_match('/OPR\/|Opera/', $ua) === 1 => 'Opera',
        preg_match('/SamsungBrowser\//', $ua) === 1 => 'Samsung Internet',
        preg_match('/Firefox\/|FxiOS\//', $ua) === 1 => 'Firefox',
        preg_match('/Chrome\/|CriOS\//', $ua) === 1 => 'Chrome',
        preg_match('/Safari\//', $ua) === 1 && preg_match('/Version\//', $ua) === 1 => 'Safari',
        default => 'Other',
    };

    $os = match (true) {
        preg_match('/iPhone|iPad|iPod/', $ua) === 1 => 'iOS',
        preg_match('/Android/', $ua) === 1 => 'Android',
        preg_match('/Windows/', $ua) === 1 => 'Windows',
        preg_match('/Mac OS X|Macintosh/', $ua) === 1 => 'macOS',
        preg_match('/CrOS/', $ua) === 1 => 'ChromeOS',
        preg_match('/Linux/', $ua) === 1 => 'Linux',
        default => 'Other',
    };

    return ['device' => $device, 'browser' => $browser, 'os' => $os];
}

function analyticsReferrerHost(string $referrer): ?string
{
    $referrer = trim($referrer);
    if ($referrer === '' || !preg_match('/^https?:\/\//i', $referrer)) {
        return null;
    }
    $host = analyticsCleanHost((string) parse_url($referrer, PHP_URL_HOST));
    return $host !== '' && mb_strlen($host) <= 190 ? $host : null;
}

function analyticsSourceType(?string $referrerHost, array $utm, array $siteHosts): string
{
    if (trim((string) ($utm['source'] ?? '')) !== '' || trim((string) ($utm['campaign'] ?? '')) !== '') {
        return 'campaign';
    }
    if ($referrerHost === null) {
        return 'direct';
    }
    if (in_array($referrerHost, $siteHosts, true)) {
        return 'internal';
    }
    if (preg_match('/(^|\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia|startpage|qwant|brave)\./', $referrerHost) === 1) {
        return 'search';
    }
    if (preg_match('/(^|\.)(linkedin|lnkd|facebook|fb|instagram|twitter|x|t|youtube|youtu|whatsapp|wa|tiktok|pinterest|reddit|threads)\.(com|me|co|in|net)$/', $referrerHost) === 1) {
        return 'social';
    }
    return 'referral';
}

/** Clean page path, or null when the URL must not be recorded as a page. */
function analyticsNormalizePath(string $path): ?string
{
    $path = trim($path);
    if ($path === '' || $path[0] !== '/') {
        return null;
    }

    $path = (string) (parse_url('https://x' . $path, PHP_URL_PATH) ?? '/');
    $path = rawurldecode($path);
    $path = mb_strtolower(preg_replace('/\/{2,}/', '/', $path) ?? $path);

    if (preg_match('/^\/(api|admin|_next|uploads|assets)(\/|$)/', $path) === 1) {
        return null;
    }
    if (preg_match('/\.(pdf|docx?|xlsx?|pptx?|zip|png|jpe?g|webp|gif|svg|ico|txt|xml|json|js|css)$/', $path) === 1) {
        return null;
    }
    if (!str_ends_with($path, '/')) {
        $path .= '/';
    }

    return mb_substr($path, 0, 500);
}

function analyticsUtm(mixed $utm): array
{
    $clean = static function (mixed $value): ?string {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/[^\p{L}\p{N} ._\-+]/u', '', $value) ?? '';
        return $value !== '' ? mb_substr($value, 0, 100) : null;
    };
    $utm = is_array($utm) ? $utm : [];

    return [
        'source' => $clean($utm['source'] ?? ''),
        'medium' => $clean($utm['medium'] ?? ''),
        'campaign' => $clean($utm['campaign'] ?? ''),
    ];
}

function analyticsCountry(): ?string
{
    if (!analyticsTrustCloudflareCountry()) {
        return null;
    }
    $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    return preg_match('/^[A-Z]{2}$/', $country) === 1 && !in_array($country, ['XX', 'T1'], true) ? $country : null;
}

function analyticsLocale(mixed $locale): string
{
    return $locale === 'fr' ? 'fr' : 'en';
}

function analyticsDoNotTrackRequested(): bool
{
    return trim((string) ($_SERVER['HTTP_DNT'] ?? '')) === '1'
        || trim((string) ($_SERVER['HTTP_SEC_GPC'] ?? '')) === '1';
}

function analyticsEventNames(): array
{
    return [
        'contact_submitted', 'application_submitted', 'cv_uploaded', 'media_opened', 'media_downloaded',
        'assistant_question', 'phone_click', 'email_click', 'language_switch', 'outbound_click',
    ];
}

/** Only short, non-personal labels are stored; anything else is dropped. */
function analyticsCleanLabel(string $event, mixed $label): ?string
{
    $label = trim((string) $label);
    if ($label === '') {
        return null;
    }

    $pattern = match ($event) {
        'media_opened', 'media_downloaded' => '/^[A-Za-z0-9][A-Za-z0-9._\-\/ ]{0,79}$/',
        'phone_click' => '/^(NG|GH|CI)$/',
        'language_switch' => '/^(en|fr)$/',
        'outbound_click' => '/^[a-z0-9.\-]{3,120}$/',
        default => null,
    };

    if ($pattern === null) {
        return null;
    }
    if ($event === 'outbound_click') {
        $label = analyticsCleanHost($label);
    }
    if ($event === 'phone_click') {
        $label = strtoupper($label);
    }

    return preg_match($pattern, $label) === 1 ? $label : null;
}

/* ------------------------------------------------------------------------ */
/* Recording                                                                 */
/* ------------------------------------------------------------------------ */

function analyticsRateLimited(mysqli $conn, string $visitorHash): bool
{
    $cutoff = analyticsNow()->modify('-60 seconds')->format('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        'SELECT (SELECT COUNT(*) FROM analytics_pageviews WHERE visitor_hash = ? AND occurred_at >= ?)
              + (SELECT COUNT(*) FROM analytics_events WHERE visitor_hash = ? AND occurred_at >= ?) AS total'
    );
    $stmt->bind_param('ssss', $visitorHash, $cutoff, $visitorHash, $cutoff);
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    return $total >= analyticsRateLimitPerMinute();
}

function analyticsCleanPageViewId(mixed $value): ?string
{
    $value = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) $value) ?? '');
    return strlen($value) === 32 ? $value : null;
}

/**
 * @param array{path: string, locale: string, referrer: string, utm: array} $input
 * @param array{device: string, browser: string, os: string, country: ?string} $client
 */
function analyticsRecordPageview(mysqli $conn, string $visitorHash, string $pageViewId, array $input, array $client, ?DateTimeImmutable $at = null): bool
{
    $path = analyticsNormalizePath((string) ($input['path'] ?? ''));
    if ($path === null) {
        return false;
    }

    $at ??= analyticsNow();
    $now = $at->format('Y-m-d H:i:s');
    $cutoff = $at->modify('-' . ANALYTICS_VISIT_GAP_SECONDS . ' seconds')->format('Y-m-d H:i:s');
    $locale = analyticsLocale($input['locale'] ?? 'en');
    $utm = analyticsUtm($input['utm'] ?? []);
    $siteHosts = analyticsAllowedHosts();
    $referrerHost = analyticsReferrerHost((string) ($input['referrer'] ?? ''));
    $sourceType = analyticsSourceType($referrerHost, $utm, $siteHosts);
    if ($sourceType === 'internal') {
        $referrerHost = null;
    }

    $find = $conn->prepare(
        'SELECT id FROM analytics_visits WHERE visitor_hash = ? AND last_seen_at >= ? ORDER BY last_seen_at DESC LIMIT 1'
    );
    $find->bind_param('ss', $visitorHash, $cutoff);
    $find->execute();
    $visit = $find->get_result()->fetch_assoc();
    $find->close();

    $isEntry = 0;
    if ($visit) {
        $visitId = (int) $visit['id'];
        // Same visit: a new campaign/referrer mid-visit does not change its source.
        $pageSource = 'internal';
    } else {
        $isEntry = 1;
        $visitSource = $sourceType === 'internal' ? 'direct' : $sourceType;
        $insertVisit = $conn->prepare(
            'INSERT INTO analytics_visits
                (visitor_hash, started_at, last_seen_at, pageviews, engaged_ms, entry_path, exit_path, source_type,
                 referrer_host, utm_source, utm_medium, utm_campaign, device_type, browser, os, country, locale)
             VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertVisit->bind_param(
            'sssssssssssssss',
            $visitorHash, $now, $now, $path, $path, $visitSource, $referrerHost,
            $utm['source'], $utm['medium'], $utm['campaign'],
            $client['device'], $client['browser'], $client['os'], $client['country'], $locale
        );
        $insertVisit->execute();
        $visitId = (int) $conn->insert_id;
        $insertVisit->close();
        $pageSource = $visitSource;
    }

    $insert = $conn->prepare(
        'INSERT IGNORE INTO analytics_pageviews
            (occurred_at, visitor_hash, visit_id, page_view_id, path, locale, referrer_host, source_type,
             utm_source, utm_medium, utm_campaign, device_type, browser, os, country, is_entry)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->bind_param(
        'ssissssssssssssi',
        $now, $visitorHash, $visitId, $pageViewId, $path, $locale, $referrerHost, $pageSource,
        $utm['source'], $utm['medium'], $utm['campaign'],
        $client['device'], $client['browser'], $client['os'], $client['country'], $isEntry
    );
    $insert->execute();
    $inserted = $insert->affected_rows === 1;
    $insert->close();

    if ($inserted) {
        $update = $conn->prepare(
            'UPDATE analytics_visits SET pageviews = pageviews + 1, last_seen_at = ?, exit_path = ? WHERE id = ?'
        );
        $update->bind_param('ssi', $now, $path, $visitId);
        $update->execute();
        $update->close();
    }

    return $inserted;
}

/** Engagement only ever increases, and is capped at 30 minutes per page view. */
function analyticsRecordEngagement(mysqli $conn, string $visitorHash, string $pageViewId, int $engagedMs, int $scrollPct): bool
{
    $find = $conn->prepare(
        'SELECT id, visit_id, engaged_ms, max_scroll_pct FROM analytics_pageviews WHERE page_view_id = ? AND visitor_hash = ?'
    );
    $find->bind_param('ss', $pageViewId, $visitorHash);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    if (!$row) {
        return false;
    }

    $oldMs = (int) $row['engaged_ms'];
    $newMs = max($oldMs, min(ANALYTICS_MAX_ENGAGED_MS, max(0, $engagedMs)));
    $newScroll = max((int) $row['max_scroll_pct'], min(100, max(0, $scrollPct)));
    $delta = $newMs - $oldMs;
    $id = (int) $row['id'];

    $update = $conn->prepare('UPDATE analytics_pageviews SET engaged_ms = ?, max_scroll_pct = ? WHERE id = ?');
    $update->bind_param('iii', $newMs, $newScroll, $id);
    $update->execute();
    $update->close();

    if ($delta > 0 && $row['visit_id'] !== null) {
        $visitId = (int) $row['visit_id'];
        $visit = $conn->prepare('UPDATE analytics_visits SET engaged_ms = engaged_ms + ? WHERE id = ?');
        $visit->bind_param('ii', $delta, $visitId);
        $visit->execute();
        $visit->close();
    }

    return true;
}

function analyticsRecordEvent(mysqli $conn, string $visitorHash, ?string $pageViewId, string $name, mixed $label, ?DateTimeImmutable $at = null): bool
{
    if (!in_array($name, analyticsEventNames(), true)) {
        return false;
    }

    $at ??= analyticsNow();
    $now = $at->format('Y-m-d H:i:s');
    $label = analyticsCleanLabel($name, $label);
    $visitId = null;
    $path = '/';
    $locale = 'en';

    if ($pageViewId !== null) {
        $find = $conn->prepare('SELECT visit_id, path, locale FROM analytics_pageviews WHERE page_view_id = ? AND visitor_hash = ?');
        $find->bind_param('ss', $pageViewId, $visitorHash);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        if ($row) {
            $visitId = $row['visit_id'] !== null ? (int) $row['visit_id'] : null;
            $path = (string) $row['path'];
            $locale = (string) $row['locale'];
        }
    }

    $insert = $conn->prepare(
        'INSERT INTO analytics_events (occurred_at, visitor_hash, visit_id, event_name, path, locale, label) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->bind_param('ssissss', $now, $visitorHash, $visitId, $name, $path, $locale, $label);
    $insert->execute();
    $insert->close();

    return true;
}

/* ------------------------------------------------------------------------ */
/* Daily roll-up, retention                                                  */
/* ------------------------------------------------------------------------ */

/** Recompute analytics_daily_stats for one date from the raw tables. */
function analyticsRollUpDay(mysqli $conn, string $date): void
{
    $start = $date . ' 00:00:00';
    $end = (new DateTimeImmutable($date, appTimeZone()))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $bounceMs = ANALYTICS_BOUNCE_ENGAGED_MS;

    // Tests wrap everything in their own transaction; do not commit it early.
    $ownTransaction = empty($GLOBALS['lambert_analytics_outer_transaction']);
    if ($ownTransaction) {
        $conn->begin_transaction();
    }
    try {
        $delete = $conn->prepare('DELETE FROM analytics_daily_stats WHERE stat_date = ?');
        $delete->bind_param('s', $date);
        $delete->execute();
        $delete->close();

        $insertSql = 'INSERT INTO analytics_daily_stats
            (stat_date, dimension, dimension_value, visitors, visits, pageviews, bounces, engaged_ms, scroll_total, events) ';

        // Visit-based dimensions: everything is attributed to the day the visit started.
        $visitDimensions = [
            'total' => "'all'",
            'source' => 'source_type',
            'referrer' => 'referrer_host',
            'campaign' => 'utm_campaign',
            'device' => 'device_type',
            'browser' => 'browser',
            'os' => 'os',
            'country' => "COALESCE(country, '--')",
            'locale' => 'locale',
            'entry' => 'entry_path',
            'exit' => 'exit_path',
        ];
        foreach ($visitDimensions as $dimension => $expression) {
            $sql = $insertSql . "
                SELECT ?, ?, LEFT({$expression}, 190), COUNT(DISTINCT visitor_hash), COUNT(*), SUM(pageviews),
                       SUM(CASE WHEN pageviews <= 1 AND engaged_ms < {$bounceMs} THEN 1 ELSE 0 END), SUM(engaged_ms), 0, 0
                FROM analytics_visits
                WHERE started_at >= ? AND started_at < ? AND {$expression} IS NOT NULL
                GROUP BY LEFT({$expression}, 190)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssss', $date, $dimension, $start, $end);
            $stmt->execute();
            $stmt->close();
        }

        // Page-based dimension.
        $stmt = $conn->prepare($insertSql . '
            SELECT ?, \'page\', LEFT(path, 190), COUNT(DISTINCT visitor_hash), COUNT(DISTINCT visit_id), COUNT(*), 0,
                   SUM(engaged_ms), SUM(max_scroll_pct), 0
            FROM analytics_pageviews WHERE occurred_at >= ? AND occurred_at < ? GROUP BY LEFT(path, 190)');
        $stmt->bind_param('sss', $date, $start, $end);
        $stmt->execute();
        $stmt->close();

        // Events and their labels.
        $stmt = $conn->prepare($insertSql . '
            SELECT ?, \'event\', event_name, COUNT(DISTINCT visitor_hash), COUNT(DISTINCT visit_id), 0, 0, 0, 0, COUNT(*)
            FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? GROUP BY event_name');
        $stmt->bind_param('sss', $date, $start, $end);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare($insertSql . '
            SELECT ?, \'event_label\', LEFT(CONCAT(event_name, \'|\', label), 190), COUNT(DISTINCT visitor_hash),
                   COUNT(DISTINCT visit_id), 0, 0, 0, 0, COUNT(*)
            FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ? AND label IS NOT NULL
            GROUP BY LEFT(CONCAT(event_name, \'|\', label), 190)');
        $stmt->bind_param('sss', $date, $start, $end);
        $stmt->execute();
        $stmt->close();

        // Daily totals also need the total event count.
        $stmt = $conn->prepare(
            "UPDATE analytics_daily_stats SET events = (SELECT COUNT(*) FROM analytics_events WHERE occurred_at >= ? AND occurred_at < ?)
             WHERE stat_date = ? AND dimension = 'total'"
        );
        $stmt->bind_param('sss', $start, $end, $date);
        $stmt->execute();
        $stmt->close();

        $final = $date < analyticsToday() ? 1 : 0;
        $rolledAt = analyticsNow()->format('Y-m-d H:i:s');
        $mark = $conn->prepare(
            'INSERT INTO analytics_rollups (stat_date, rolled_at, is_final) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rolled_at = VALUES(rolled_at), is_final = VALUES(is_final)'
        );
        $mark->bind_param('ssi', $date, $rolledAt, $final);
        $mark->execute();
        $mark->close();

        if ($ownTransaction) {
            $conn->commit();
        }
    } catch (Throwable $error) {
        if ($ownTransaction) {
            $conn->rollback();
        }
        throw $error;
    }
}

/** Roll up every day in the range that is not final yet (today is always refreshed). */
function analyticsEnsureRollups(mysqli $conn, string $from, string $to): void
{
    $today = analyticsToday();
    $retentionStart = analyticsNow()->modify('-' . analyticsRetentionDays() . ' days')->format('Y-m-d');

    $final = [];
    $stmt = $conn->prepare('SELECT stat_date FROM analytics_rollups WHERE stat_date BETWEEN ? AND ? AND is_final = 1');
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $final[(string) $row['stat_date']] = true;
    }
    $stmt->close();

    $cursor = new DateTimeImmutable(max($from, $retentionStart), appTimeZone());
    $last = new DateTimeImmutable(min($to, $today), appTimeZone());
    while ($cursor <= $last) {
        $date = $cursor->format('Y-m-d');
        if (!isset($final[$date])) {
            analyticsRollUpDay($conn, $date);
        }
        $cursor = $cursor->modify('+1 day');
    }
}

/** Roll up finished days, then delete raw rows older than the retention period. */
function analyticsMaintenance(mysqli $conn): array
{
    $days = analyticsRetentionDays();
    $cutoffDate = analyticsNow()->modify('-' . $days . ' days')->format('Y-m-d');
    $yesterday = analyticsNow()->modify('-1 day')->format('Y-m-d');

    // Make sure everything about to be purged has final daily totals first.
    $oldest = $conn->query('SELECT DATE(MIN(started_at)) AS d FROM analytics_visits')->fetch_assoc()['d'] ?? null;
    if ($oldest !== null) {
        analyticsEnsureRollups($conn, max((string) $oldest, $cutoffDate), $yesterday);
    }
    $leftovers = $conn->prepare('SELECT DISTINCT DATE(started_at) AS d FROM analytics_visits WHERE started_at < ?');
    $cutoff = $cutoffDate . ' 00:00:00';
    $leftovers->bind_param('s', $cutoff);
    $leftovers->execute();
    $result = $leftovers->get_result();
    while ($row = $result->fetch_assoc()) {
        $check = $conn->prepare('SELECT is_final FROM analytics_rollups WHERE stat_date = ?');
        $date = (string) $row['d'];
        $check->bind_param('s', $date);
        $check->execute();
        $isFinal = (int) ($check->get_result()->fetch_assoc()['is_final'] ?? 0);
        $check->close();
        if ($isFinal !== 1) {
            analyticsRollUpDay($conn, $date);
        }
    }
    $leftovers->close();

    $purged = 0;
    foreach (['analytics_pageviews' => 'occurred_at', 'analytics_events' => 'occurred_at', 'analytics_visits' => 'started_at'] as $table => $column) {
        $stmt = $conn->prepare("DELETE FROM {$table} WHERE {$column} < ?");
        $stmt->bind_param('s', $cutoff);
        $stmt->execute();
        $purged += $stmt->affected_rows;
        $stmt->close();
    }

    $saltCutoff = $yesterday;
    $stmt = $conn->prepare('DELETE FROM analytics_salts WHERE salt_date < ?');
    $stmt->bind_param('s', $saltCutoff);
    $stmt->execute();
    $stmt->close();

    return ['retention_days' => $days, 'purged_rows' => $purged];
}

/* ------------------------------------------------------------------------ */
/* Reports                                                                   */
/* ------------------------------------------------------------------------ */

/** @return array{0: string, 1: string} validated [from, to] (Y-m-d) */
function analyticsValidatedRange(mixed $from, mixed $to): array
{
    $zone = appTimeZone();
    $today = analyticsNow();
    $parse = static function (mixed $value) use ($zone): ?DateTimeImmutable {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    };

    $toDate = $parse($to) ?? $today;
    $fromDate = $parse($from) ?? $toDate->modify('-29 days');
    if ($toDate > $today) {
        $toDate = $today;
    }
    if ($fromDate > $toDate) {
        throw new InvalidArgumentException('The start date must be before the end date.');
    }
    if ((int) $fromDate->diff($toDate)->format('%a') + 1 > ANALYTICS_MAX_RANGE_DAYS) {
        throw new InvalidArgumentException('Choose a period of ' . ANALYTICS_MAX_RANGE_DAYS . ' days or less.');
    }

    return [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
}

function analyticsDimensionRows(mysqli $conn, string $dimension, string $from, string $to, int $limit = 10, string $orderBy = 'visits'): array
{
    $order = in_array($orderBy, ['visits', 'pageviews', 'events', 'visitors'], true) ? $orderBy : 'visits';
    $stmt = $conn->prepare(
        "SELECT dimension_value AS value, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(pageviews) AS pageviews,
                SUM(bounces) AS bounces, SUM(engaged_ms) AS engaged_ms, SUM(scroll_total) AS scroll_total, SUM(events) AS events
         FROM analytics_daily_stats
         WHERE dimension = ? AND stat_date BETWEEN ? AND ?
         GROUP BY dimension_value
         ORDER BY {$order} DESC, dimension_value ASC
         LIMIT ?"
    );
    $stmt->bind_param('sssi', $dimension, $from, $to, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $pageviews = (int) $row['pageviews'];
        $visits = (int) $row['visits'];
        $rows[] = [
            'value' => (string) $row['value'],
            'visitors' => (int) $row['visitors'],
            'visits' => $visits,
            'pageviews' => $pageviews,
            'events' => (int) $row['events'],
            'bounce_rate' => $visits > 0 ? round((int) $row['bounces'] / $visits * 100, 1) : 0.0,
            'avg_engaged_seconds' => $dimension === 'page'
                ? ($pageviews > 0 ? (int) round((int) $row['engaged_ms'] / $pageviews / 1000) : 0)
                : ($visits > 0 ? (int) round((int) $row['engaged_ms'] / $visits / 1000) : 0),
            'avg_scroll_pct' => $dimension === 'page' && $pageviews > 0 ? (int) round((int) $row['scroll_total'] / $pageviews) : null,
        ];
    }
    $stmt->close();

    return $rows;
}

function analyticsTotals(mysqli $conn, string $from, string $to): array
{
    $rows = analyticsDimensionRows($conn, 'total', $from, $to, 1);
    $total = $rows[0] ?? ['visitors' => 0, 'visits' => 0, 'pageviews' => 0];
    $events = [];
    foreach (analyticsDimensionRows($conn, 'event', $from, $to, 50, 'events') as $row) {
        $events[$row['value']] = $row['events'];
    }

    $stmt = $conn->prepare(
        "SELECT SUM(bounces) AS bounces, SUM(engaged_ms) AS engaged_ms FROM analytics_daily_stats
         WHERE dimension = 'total' AND stat_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $sums = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $visits = (int) $total['visits'];
    $pageviews = (int) $total['pageviews'];

    return [
        'visitors' => (int) $total['visitors'],
        'visits' => $visits,
        'pageviews' => $pageviews,
        'pages_per_visit' => $visits > 0 ? round($pageviews / $visits, 2) : 0.0,
        'avg_visit_seconds' => $visits > 0 ? (int) round((int) ($sums['engaged_ms'] ?? 0) / $visits / 1000) : 0,
        'bounce_rate' => $visits > 0 ? round((int) ($sums['bounces'] ?? 0) / $visits * 100, 1) : 0.0,
        'enquiries' => (int) ($events['contact_submitted'] ?? 0),
        'applications' => (int) ($events['application_submitted'] ?? 0),
    ];
}

function analyticsTimeseries(mysqli $conn, string $from, string $to): array
{
    // A single day is shown per hour (from raw rows, when still retained).
    if ($from === $to) {
        $start = $from . ' 00:00:00';
        $end = (new DateTimeImmutable($from, appTimeZone()))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $hours = array_fill(0, 24, ['visitors' => 0, 'visits' => 0, 'pageviews' => 0]);
        $stmt = $conn->prepare(
            'SELECT HOUR(occurred_at) AS h, COUNT(DISTINCT visitor_hash) AS visitors, COUNT(DISTINCT visit_id) AS visits, COUNT(*) AS pageviews
             FROM analytics_pageviews WHERE occurred_at >= ? AND occurred_at < ? GROUP BY HOUR(occurred_at)'
        );
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $hours[(int) $row['h']] = ['visitors' => (int) $row['visitors'], 'visits' => (int) $row['visits'], 'pageviews' => (int) $row['pageviews']];
        }
        $stmt->close();

        $points = [];
        foreach ($hours as $hour => $values) {
            $points[] = ['key' => sprintf('%s %02d:00', $from, $hour), 'label' => sprintf('%02d:00', $hour)] + $values;
        }
        return ['granularity' => 'hour', 'points' => $points];
    }

    $byDate = [];
    $stmt = $conn->prepare(
        "SELECT stat_date, visitors, visits, pageviews FROM analytics_daily_stats
         WHERE dimension = 'total' AND stat_date BETWEEN ? AND ?"
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $byDate[(string) $row['stat_date']] = ['visitors' => (int) $row['visitors'], 'visits' => (int) $row['visits'], 'pageviews' => (int) $row['pageviews']];
    }
    $stmt->close();

    $points = [];
    $cursor = new DateTimeImmutable($from, appTimeZone());
    $last = new DateTimeImmutable($to, appTimeZone());
    while ($cursor <= $last) {
        $date = $cursor->format('Y-m-d');
        $points[] = ['key' => $date, 'label' => $cursor->format('j M')] + ($byDate[$date] ?? ['visitors' => 0, 'visits' => 0, 'pageviews' => 0]);
        $cursor = $cursor->modify('+1 day');
    }

    return ['granularity' => 'day', 'points' => $points];
}

function analyticsRealtime(mysqli $conn): array
{
    $cutoff = analyticsNow()->modify('-30 minutes')->format('Y-m-d H:i:s');
    $stmt = $conn->prepare('SELECT COUNT(DISTINCT visitor_hash) AS visitors FROM analytics_pageviews WHERE occurred_at >= ?');
    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $visitors = (int) ($stmt->get_result()->fetch_assoc()['visitors'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare(
        'SELECT path, COUNT(*) AS pageviews FROM analytics_pageviews WHERE occurred_at >= ? GROUP BY path ORDER BY pageviews DESC LIMIT 5'
    );
    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $pages = [];
    while ($row = $result->fetch_assoc()) {
        $pages[] = ['path' => (string) $row['path'], 'pageviews' => (int) $row['pageviews']];
    }
    $stmt->close();

    return ['visitors' => $visitors, 'pages' => $pages];
}

function analyticsPercentChange(float|int $current, float|int $previous): ?float
{
    if ((float) $previous === 0.0) {
        return (float) $current === 0.0 ? 0.0 : null;
    }
    return round(((float) $current - (float) $previous) / (float) $previous * 100, 1);
}

function analyticsReport(mysqli $conn, string $from, string $to, bool $compare): array
{
    $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%a') + 1;
    $previousTo = (new DateTimeImmutable($from, appTimeZone()))->modify('-1 day')->format('Y-m-d');
    $previousFrom = (new DateTimeImmutable($previousTo, appTimeZone()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

    analyticsEnsureRollups($conn, $compare ? $previousFrom : $from, $to);

    $totals = analyticsTotals($conn, $from, $to);
    $previous = null;
    $changes = null;
    if ($compare) {
        $previous = analyticsTotals($conn, $previousFrom, $previousTo);
        $changes = [];
        foreach ($totals as $key => $value) {
            $changes[$key] = analyticsPercentChange($value, $previous[$key]);
        }
    }

    $events = analyticsDimensionRows($conn, 'event', $from, $to, 20, 'events');
    $visits = max(1, $totals['visits']);
    foreach ($events as &$event) {
        $event['conversion_rate'] = round($event['visits'] / $visits * 100, 1);
    }
    unset($event);

    $labels = [];
    foreach (analyticsDimensionRows($conn, 'event_label', $from, $to, 30, 'events') as $row) {
        [$name, $label] = array_pad(explode('|', $row['value'], 2), 2, '');
        $labels[] = ['event' => $name, 'label' => $label, 'events' => $row['events']];
    }

    return [
        'enabled' => analyticsEnabled(),
        'range' => ['from' => $from, 'to' => $to, 'days' => $days],
        'previous_range' => $compare ? ['from' => $previousFrom, 'to' => $previousTo] : null,
        'totals' => $totals,
        'previous_totals' => $previous,
        'changes' => $changes,
        'timeseries' => analyticsTimeseries($conn, $from, $to),
        'previous_timeseries' => $compare ? analyticsTimeseries($conn, $previousFrom, $previousTo) : null,
        'top_pages' => analyticsDimensionRows($conn, 'page', $from, $to, 50, 'pageviews'),
        'entry_pages' => analyticsDimensionRows($conn, 'entry', $from, $to, 20),
        'exit_pages' => analyticsDimensionRows($conn, 'exit', $from, $to, 20),
        'sources' => analyticsDimensionRows($conn, 'source', $from, $to, 10),
        'referrers' => analyticsDimensionRows($conn, 'referrer', $from, $to, 15),
        'campaigns' => analyticsDimensionRows($conn, 'campaign', $from, $to, 15),
        'devices' => analyticsDimensionRows($conn, 'device', $from, $to, 5),
        'browsers' => analyticsDimensionRows($conn, 'browser', $from, $to, 10),
        'operating_systems' => analyticsDimensionRows($conn, 'os', $from, $to, 10),
        'countries' => analyticsDimensionRows($conn, 'country', $from, $to, 15),
        'languages' => analyticsDimensionRows($conn, 'locale', $from, $to, 5),
        'events' => $events,
        'event_labels' => $labels,
        'realtime' => analyticsRealtime($conn),
        'country_source' => analyticsTrustCloudflareCountry() ? 'cloudflare' : 'none',
    ];
}

/** Rows for CSV export. @return array{0: list<string>, 1: list<list<scalar>>} */
function analyticsExportRows(mysqli $conn, string $report, string $from, string $to): array
{
    analyticsEnsureRollups($conn, $from, $to);

    switch ($report) {
        case 'pages':
            $rows = array_map(static fn (array $r): array => [$r['value'], $r['pageviews'], $r['visitors'], $r['avg_engaged_seconds'], $r['avg_scroll_pct']],
                analyticsDimensionRows($conn, 'page', $from, $to, 1000, 'pageviews'));
            return [['Page', 'Page views', 'Visitors', 'Avg. time on page (s)', 'Avg. scroll (%)'], $rows];
        case 'sources':
            $rows = [];
            foreach (['source' => 'Source type', 'referrer' => 'Referrer', 'campaign' => 'Campaign'] as $dimension => $label) {
                foreach (analyticsDimensionRows($conn, $dimension, $from, $to, 1000) as $r) {
                    $rows[] = [$label, $r['value'], $r['visits'], $r['visitors'], $r['bounce_rate']];
                }
            }
            return [['Type', 'Value', 'Visits', 'Visitors', 'Bounce rate (%)'], $rows];
        case 'events':
            $rows = array_map(static fn (array $r): array => [$r['value'], $r['events'], $r['visits']],
                analyticsDimensionRows($conn, 'event', $from, $to, 100, 'events'));
            foreach (analyticsDimensionRows($conn, 'event_label', $from, $to, 1000, 'events') as $r) {
                $rows[] = [$r['value'], $r['events'], $r['visits']];
            }
            return [['Action (action|label)', 'Count', 'Visits'], $rows];
        case 'daily':
        default:
            $rows = [];
            $stmt = $conn->prepare(
                "SELECT stat_date, visitors, visits, pageviews, bounces, engaged_ms, events FROM analytics_daily_stats
                 WHERE dimension = 'total' AND stat_date BETWEEN ? AND ? ORDER BY stat_date"
            );
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($r = $result->fetch_assoc()) {
                $visits = (int) $r['visits'];
                $rows[] = [
                    (string) $r['stat_date'], (int) $r['visitors'], $visits, (int) $r['pageviews'],
                    $visits > 0 ? round((int) $r['bounces'] / $visits * 100, 1) : 0,
                    $visits > 0 ? (int) round((int) $r['engaged_ms'] / $visits / 1000) : 0,
                    (int) $r['events'],
                ];
            }
            $stmt->close();
            return [['Date', 'Visitors', 'Visits', 'Page views', 'Bounce rate (%)', 'Avg. visit (s)', 'Actions'], $rows];
    }
}
