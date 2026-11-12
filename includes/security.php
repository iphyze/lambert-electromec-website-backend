<?php

declare(strict_types=1);

use Dotenv\Dotenv;

function loadAppEnvironment(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $root = dirname(__DIR__);
    if (is_file($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }

    $loaded = true;
}

loadAppEnvironment();

function envValue(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

function envBoolean(string $key, bool $default = false): bool
{
    $value = envValue($key, null);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function appEnvironment(): string
{
    return strtolower(trim((string) envValue('APP_ENV', 'local')));
}


function applicationHashSecret(string $specificKey): string
{
    $secret = trim((string) envValue($specificKey, envValue('APP_HASH_SECRET', envValue('JWT_SECRET', ''))));
    if (strlen($secret) < 32) {
        throw new RuntimeException(
            $specificKey . ' (or APP_HASH_SECRET/JWT_SECRET fallback) must contain at least 32 random characters.',
            500
        );
    }

    return $secret;
}

function registerApiExceptionHandler(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }

    if (appEnvironment() === 'production') {
        ini_set('display_errors', '0');
    }

    set_exception_handler(static function (Throwable $error): never {
        error_log(sprintf(
            'Lambert API uncaught %s: %s in %s:%d',
            get_class($error),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        ));

        $code = (int) $error->getCode();
        $statusCode = ($code >= 400 && $code <= 499) ? $code : 500;
        $message = $statusCode < 500 && trim($error->getMessage()) !== ''
            ? $error->getMessage()
            : 'Internal server error.';

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'status' => 'Failed',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    });

    $registered = true;
}

registerApiExceptionHandler();

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requestJsonBody(int $maxBytes = 1048576): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        jsonResponse(['status' => 'Failed', 'message' => 'Request payload is too large.'], 413);
    }

    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        jsonResponse(['status' => 'Failed', 'message' => 'Request payload is too large.'], 413);
    }

    if (trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid JSON payload.'], 400);
    }

    if (!is_array($decoded)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid request payload.'], 400);
    }

    return $decoded;
}

function requireMethod(string ...$allowedMethods): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper', $allowedMethods);

    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        jsonResponse(['status' => 'Failed', 'message' => 'Method not allowed.'], 405);
    }
}

function allowedOrigins(): array
{
    $configured = (string) envValue(
        'ALLOWED_ORIGINS',
        'http://localhost:3000,http://127.0.0.1:3000'
    );

    return array_values(array_unique(array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', $configured)
    ))));
}

function applyApiSecurityHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    if (appEnvironment() === 'production' && envBoolean('COOKIE_SECURE', true)) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $origin = rtrim(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
    $trustedOrigin = $origin !== '' && in_array($origin, allowedOrigins(), true);

    if ($trustedOrigin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With, Idempotency-Key');
    header('Access-Control-Max-Age: 600');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        if ($origin !== '' && !$trustedOrigin) {
            jsonResponse(['status' => 'Failed', 'message' => 'Origin is not allowed.'], 403);
        }

        http_response_code(204);
        exit;
    }
}

function applyNoStoreHeaders(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function refreshCookieName(): string
{
    return (string) envValue('REFRESH_COOKIE_NAME', 'lambert_admin_refresh');
}

function csrfCookieName(): string
{
    return (string) envValue('CSRF_COOKIE_NAME', 'lambert_admin_csrf');
}

function configuredApiBasePath(): string
{
    $configured = trim((string) envValue('API_BASE_PATH', ''));
    if ($configured === '') {
        return '';
    }

    return '/' . trim($configured, '/');
}

function resolvedApiBasePath(): string
{
    $configured = configuredApiBasePath();
    if ($configured !== '') {
        return $configured;
    }

    // Derive the public API path from the actual executing front controller,
    // not SCRIPT_NAME/PHP_SELF. Under Apache mod_rewrite (especially XAMPP),
    // those values can retain the originally requested route such as
    // /lambert-api/auth/login, which would make the router strip the
    // wrong path prefix.
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $apiRoot = realpath(dirname(__DIR__));

    if ($documentRoot !== false && $apiRoot !== false) {
        $documentRootNormalized = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $apiRootNormalized = rtrim(str_replace('\\', '/', $apiRoot), '/');

        if (strcasecmp($apiRootNormalized, $documentRootNormalized) === 0) {
            return '';
        }

        $documentPrefix = $documentRootNormalized . '/';
        if (strncasecmp($apiRootNormalized, $documentPrefix, strlen($documentPrefix)) === 0) {
            $relative = substr($apiRootNormalized, strlen($documentRootNormalized));
            return '/' . trim($relative, '/');
        }
    }

    // Fallback for hosts where DOCUMENT_ROOT cannot be resolved reliably.
    $scriptFilename = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($scriptFilename !== false && basename($scriptFilename) === 'index.php') {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $scriptDir = rtrim(dirname($scriptName), '/.');
        return $scriptDir === '' ? '' : $scriptDir;
    }

    return '';
}

function requestDerivedApiBasePath(): string
{
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $requestPath = '/' . ltrim(str_replace('\\', '/', $requestPath), '/');

    // Authentication endpoints are always mounted below /auth. Deriving the
    // prefix from the request itself avoids cookie-path mismatches when Apache
    // rewrites a sub-folder installation (XAMPP/cPanel) before PHP executes.
    $authMarker = '/auth/';
    $position = stripos($requestPath, $authMarker);
    if ($position !== false) {
        $basePath = substr($requestPath, 0, $position);
        return $basePath === '/' ? '' : rtrim($basePath, '/');
    }

    return resolvedApiBasePath();
}

function authCookiePath(): string
{
    $configured = trim((string) envValue('AUTH_COOKIE_PATH', ''));
    if ($configured !== '') {
        return '/' . trim($configured, '/');
    }

    $basePath = requestDerivedApiBasePath();
    return ($basePath !== '' ? $basePath : '') . '/auth';
}

function cookieSameSite(): string
{
    $sameSite = ucfirst(strtolower((string) envValue('COOKIE_SAMESITE', 'Strict')));
    return in_array($sameSite, ['Strict', 'Lax', 'None'], true) ? $sameSite : 'Strict';
}

function csrfCookiePath(): string
{
    $configured = trim((string) envValue('CSRF_COOKIE_PATH', ''));
    if ($configured !== '') {
        return '/' . trim($configured, '/');
    }

    $basePath = requestDerivedApiBasePath();
    return $basePath !== '' ? $basePath : '/';
}

function cookieOptions(bool $httpOnly, ?int $expires = null, ?string $path = null): array
{
    $secureDefault = appEnvironment() === 'production';
    $sameSite = cookieSameSite();
    $secure = envBoolean('COOKIE_SECURE', $secureDefault);
    if ($sameSite === 'None') {
        // Browsers require Secure cookies whenever SameSite=None is used.
        $secure = true;
    }

    $options = [
        'expires' => $expires ?? 0,
        'path' => $path ?: '/',
        'secure' => $secure,
        'httponly' => $httpOnly,
        'samesite' => $sameSite,
    ];

    $domain = trim((string) envValue('COOKIE_DOMAIN', ''));
    if ($domain !== '') {
        $options['domain'] = $domain;
    }

    return $options;
}

function setRefreshCookie(string $refreshToken, int $expiresAt): void
{
    setcookie(refreshCookieName(), $refreshToken, cookieOptions(true, $expiresAt, authCookiePath()));
}

function issueCsrfCookie(): string
{
    $csrfToken = bin2hex(random_bytes(32));
    $expiresAt = time() + max(3600, (int) envValue('REFRESH_TOKEN_EXPIRES_IN', 2592000));
    setcookie(csrfCookieName(), $csrfToken, cookieOptions(false, $expiresAt, csrfCookiePath()));
    return $csrfToken;
}

function clearAuthCookies(): void
{
    setcookie(refreshCookieName(), '', cookieOptions(true, time() - 3600, authCookiePath()));
    setcookie(csrfCookieName(), '', cookieOptions(false, time() - 3600, csrfCookiePath()));
}

function requireTrustedRequestOrigin(): void
{
    $origin = rtrim(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
    if ($origin !== '' && !in_array($origin, allowedOrigins(), true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Origin is not allowed.'], 403);
    }
}

function requireCsrfToken(): void
{
    requireTrustedRequestOrigin();

    $cookieToken = (string) ($_COOKIE[csrfCookieName()] ?? '');
    $headerToken = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if ($cookieToken === '' || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Security validation failed. Refresh the page and try again.',
        ], 403);
    }
}

function clientIpAddress(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function requestUserAgent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);
}

function normalizedRelativeApiPath(): string
{
    // Preferred path source: Apache passes the clean route captured by
    // .htaccess explicitly. This avoids REQUEST_URI/SCRIPT_NAME differences
    // between XAMPP, FastCGI and cPanel sub-folder deployments.
    $rewriteRoute = $_GET['__lambert_route'] ?? null;

    if (is_string($rewriteRoute) && trim($rewriteRoute) !== '') {
        unset($_GET['__lambert_route']);

        $requestPath = rawurldecode(trim($rewriteRoute));
        $requestPath = str_replace('\\', '/', $requestPath);
        $requestPath = preg_replace('#/+#', '/', $requestPath) ?: '/';
        $requestPath = '/' . ltrim($requestPath, '/');

        foreach (explode('/', trim($requestPath, '/')) as $segment) {
            if ($segment === '..') {
                jsonResponse(['status' => 'Failed', 'message' => 'Invalid API path.'], 400);
            }
        }

        return rtrim($requestPath, '/') ?: '/';
    }

    // Fallback for direct index.php execution or servers where rewrite query
    // parameters are unavailable. Strip whichever known API base path actually
    // matches the current request.
    $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $requestPath = preg_replace('#/+#', '/', $requestPath) ?: '/';

    $candidates = array_values(array_unique(array_filter([
        configuredApiBasePath(),
        resolvedApiBasePath(),
    ])));

    usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    foreach ($candidates as $basePath) {
        if ($requestPath === $basePath || str_starts_with($requestPath, $basePath . '/')) {
            $requestPath = substr($requestPath, strlen($basePath));
            break;
        }
    }

    $relative = '/' . ltrim($requestPath, '/');
    return rtrim($relative, '/') ?: '/';
}
