<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

use Firebase\JWT\JWT;

function jwtSecret(): string
{
    $secret = trim((string) envValue('JWT_SECRET', ''));
    if (strlen($secret) < 32 || in_array(strtolower($secret), ['replace_me', 'changeme', 'your_default_secret'], true)) {
        throw new RuntimeException('JWT_SECRET must be configured with at least 32 random characters.', 500);
    }

    return $secret;
}

function jwtIssuer(): string
{
    return (string) envValue('JWT_ISSUER', 'lambert-api');
}

function jwtAudience(): string
{
    return (string) envValue('JWT_AUDIENCE', 'lambert-admin');
}

function issueAccessToken(array $admin): array
{
    $issuedAt = time();
    $expiresIn = max(300, min(3600, (int) envValue('JWT_ACCESS_EXPIRES_IN', 900)));
    $expiresAt = $issuedAt + $expiresIn;

    $payload = [
        'iss' => jwtIssuer(),
        'aud' => jwtAudience(),
        'iat' => $issuedAt,
        'nbf' => $issuedAt - 5,
        'exp' => $expiresAt,
        'jti' => bin2hex(random_bytes(16)),
        'type' => 'access',
        'sub' => (string) $admin['id'],
        'id' => (int) $admin['id'],
        'email' => (string) $admin['email'],
        'role' => (string) $admin['role'],
        'token_version' => (int) ($admin['token_version'] ?? 1),
    ];

    return [
        'token' => JWT::encode($payload, jwtSecret(), 'HS256'),
        'expires_at' => $expiresAt,
    ];
}

function currentRefreshCookie(): string
{
    return trim((string) ($_COOKIE[refreshCookieName()] ?? ''));
}

function issueRefreshSession(
    mysqli $conn,
    int $adminId,
    ?string $familyId = null,
    bool $writeCookie = true
): array {
    $token = bin2hex(random_bytes(64));
    $hash = hash('sha256', $token);
    $familyId = $familyId ?: bin2hex(random_bytes(32));
    $expiresAtUnix = time() + max(3600, (int) envValue('REFRESH_TOKEN_EXPIRES_IN', 2592000));
    $expiresAt = date('Y-m-d H:i:s', $expiresAtUnix);
    $ipAddress = clientIpAddress();
    $userAgent = requestUserAgent();

    $stmt = $conn->prepare(
        'INSERT INTO auth_refresh_tokens
        (admin_user_id, token_hash, family_id, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssss', $adminId, $hash, $familyId, $expiresAt, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();

    if ($writeCookie) {
        setRefreshCookie($token, $expiresAtUnix);
    }

    return [
        'token' => $token,
        'hash' => $hash,
        'family_id' => $familyId,
        'expires_at' => $expiresAtUnix,
    ];
}

function rotateRefreshSession(mysqli $conn): array
{
    $token = currentRefreshCookie();
    if ($token === '') {
        throw new RuntimeException('Authentication required.', 401);
    }

    $hash = hash('sha256', $token);
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            'SELECT
                rt.id AS refresh_id,
                rt.admin_user_id,
                rt.family_id,
                rt.expires_at,
                rt.revoked_at,
                rt.replaced_by_hash,
                rt.last_used_at,
                rt.ip_address AS refresh_ip_address,
                rt.user_agent AS refresh_user_agent,
                a.id,
                a.first_name,
                a.last_name,
                a.email,
                a.role,
                a.status,
                a.token_version
             FROM auth_refresh_tokens rt
             INNER JOIN admin_users a ON a.id = rt.admin_user_id
             WHERE rt.token_hash = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$session || strtotime((string) $session['expires_at']) <= time()) {
            $conn->rollback();
            clearAuthCookies();
            throw new RuntimeException('Session has expired. Please sign in again.', 401);
        }

        if (!empty($session['revoked_at'])) {
            $familyId = (string) $session['family_id'];
            $revoke = $conn->prepare(
                'UPDATE auth_refresh_tokens
                 SET revoked_at = COALESCE(revoked_at, NOW())
                 WHERE family_id = ?'
            );
            $revoke->bind_param('s', $familyId);
            $revoke->execute();
            $revoke->close();
            $conn->commit();
            clearAuthCookies();
            throw new RuntimeException('Session security check failed. Please sign in again.', 401);
        }

        if (strcasecmp((string) $session['status'], 'active') !== 0) {
            $adminId = (int) $session['admin_user_id'];
            $revoke = $conn->prepare(
                'UPDATE auth_refresh_tokens
                 SET revoked_at = COALESCE(revoked_at, NOW())
                 WHERE admin_user_id = ?'
            );
            $revoke->bind_param('i', $adminId);
            $revoke->execute();
            $revoke->close();
            $conn->commit();
            clearAuthCookies();
            throw new RuntimeException('Your administrator account is inactive.', 403);
        }

        $refreshId = (int) $session['refresh_id'];
        $revokeCurrent = $conn->prepare(
            'UPDATE auth_refresh_tokens
             SET revoked_at = NOW(), last_used_at = NOW()
             WHERE id = ? AND revoked_at IS NULL'
        );
        $revokeCurrent->bind_param('i', $refreshId);
        $revokeCurrent->execute();
        if ($revokeCurrent->affected_rows !== 1) {
            $revokeCurrent->close();
            throw new RuntimeException('Session refresh could not be completed.', 401);
        }
        $revokeCurrent->close();

        $newSession = issueRefreshSession(
            $conn,
            (int) $session['admin_user_id'],
            (string) $session['family_id'],
            false
        );

        $replacementHash = (string) $newSession['hash'];
        $replace = $conn->prepare('UPDATE auth_refresh_tokens SET replaced_by_hash = ? WHERE id = ?');
        $replace->bind_param('si', $replacementHash, $refreshId);
        $replace->execute();
        $replace->close();

        $conn->commit();
        setRefreshCookie((string) $newSession['token'], (int) $newSession['expires_at']);

        return $session;
    } catch (Throwable $error) {
        if ($conn->errno === 0) {
            // mysqli does not expose transaction state portably; rollback is safe here.
        }
        try {
            $conn->rollback();
        } catch (Throwable) {
        }
        throw $error;
    }
}

function revokeCurrentRefreshFamily(mysqli $conn): void
{
    $token = currentRefreshCookie();
    if ($token !== '') {
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare('SELECT family_id FROM auth_refresh_tokens WHERE token_hash = ? LIMIT 1');
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row['family_id'])) {
            $familyId = (string) $row['family_id'];
            $revoke = $conn->prepare(
                'UPDATE auth_refresh_tokens
                 SET revoked_at = COALESCE(revoked_at, NOW())
                 WHERE family_id = ?'
            );
            $revoke->bind_param('s', $familyId);
            $revoke->execute();
            $revoke->close();
        }
    }

    clearAuthCookies();
}

function loginAttemptEmailHash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function assertLoginNotRateLimited(mysqli $conn, string $email): void
{
    $emailHash = loginAttemptEmailHash($email);
    $ipAddress = clientIpAddress();
    $windowMinutes = max(5, min(60, (int) envValue('LOGIN_WINDOW_MINUTES', 15)));
    $maxPerAccountIp = max(3, min(20, (int) envValue('LOGIN_MAX_ATTEMPTS', 5)));
    $maxPerIp = max(10, min(100, (int) envValue('LOGIN_MAX_ATTEMPTS_PER_IP', 25)));

    $sql = "SELECT
        SUM(CASE WHEN email_hash = ? THEN 1 ELSE 0 END) AS account_ip_failures,
        COUNT(*) AS ip_failures
        FROM auth_login_attempts
        WHERE ip_address = ?
          AND successful = 0
          AND attempted_at > (NOW() - INTERVAL {$windowMinutes} MINUTE)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $emailHash, $ipAddress);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ((int) ($row['account_ip_failures'] ?? 0) >= $maxPerAccountIp
        || (int) ($row['ip_failures'] ?? 0) >= $maxPerIp) {
        throw new RuntimeException('Too many sign-in attempts. Please wait and try again.', 429);
    }
}

function recordLoginAttempt(mysqli $conn, string $email, bool $successful): void
{
    $emailHash = loginAttemptEmailHash($email);
    $ipAddress = clientIpAddress();
    $successFlag = $successful ? 1 : 0;

    $stmt = $conn->prepare(
        'INSERT INTO auth_login_attempts (email_hash, ip_address, successful)
         VALUES (?, ?, ?)'
    );
    $stmt->bind_param('ssi', $emailHash, $ipAddress, $successFlag);
    $stmt->execute();
    $stmt->close();

    if ($successful) {
        $clean = $conn->prepare(
            'DELETE FROM auth_login_attempts
             WHERE email_hash = ? AND ip_address = ? AND successful = 0'
        );
        $clean->bind_param('ss', $emailHash, $ipAddress);
        $clean->execute();
        $clean->close();
    }

    $conn->query("DELETE FROM auth_login_attempts WHERE attempted_at < (NOW() - INTERVAL 2 DAY)");
}

function publicAdminPayload(array $admin): array
{
    return [
        'id' => (int) $admin['id'],
        'first_name' => (string) ($admin['first_name'] ?? ''),
        'last_name' => (string) ($admin['last_name'] ?? ''),
        'email' => (string) $admin['email'],
        'role' => (string) $admin['role'],
    ];
}

function recordAuditLog(
    mysqli $conn,
    ?int $adminId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $metadata = null
): void {
    try {
        $metadataJson = $metadata === null
            ? null
            : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ipAddress = clientIpAddress();
        $userAgent = requestUserAgent();

        $stmt = $conn->prepare(
            'INSERT INTO audit_logs
            (admin_user_id, action, entity_type, entity_id, metadata, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'ississs',
            $adminId,
            $action,
            $entityType,
            $entityId,
            $metadataJson,
            $ipAddress,
            $userAgent
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('Lambert audit log error: ' . $error->getMessage());
    }
}
