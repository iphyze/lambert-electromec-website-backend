<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function turnstileRequired(): bool
{
    if (appEnvironment() === 'production') {
        return true;
    }

    return envBoolean('TURNSTILE_REQUIRED', false);
}

function configuredTurnstileHostnames(): array
{
    $raw = trim((string) envValue('TURNSTILE_ALLOWED_HOSTNAMES', ''));
    if ($raw === '') {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        static fn (string $value): string => strtolower(trim($value)),
        preg_split('/[,;\n]+/', $raw) ?: []
    ))));
}

function verifyTurnstileToken(string $token): bool
{
    $required = turnstileRequired();
    $secret = trim((string) envValue('TURNSTILE_SECRET_KEY', ''));
    $token = trim($token);

    if (!$required && ($secret === '' || $token === '')) {
        return false;
    }

    if ($secret === '') {
        if ($required) {
            jsonResponse(['status' => 'Failed', 'message' => 'Human verification is temporarily unavailable. Please try again.'], 503);
        }
        return false;
    }

    if ($token === '') {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Please complete the human verification.',
            'field' => 'turnstile_token',
        ], 422);
    }

    if (!function_exists('curl_init')) {
        jsonResponse(['status' => 'Failed', 'message' => 'Human verification is temporarily unavailable. Please try again.'], 503);
    }

    $payload = [
        'secret' => $secret,
        'response' => $token,
    ];
    $remoteIp = clientIpAddress();
    if ($remoteIp !== '' && $remoteIp !== 'unknown') {
        $payload['remoteip'] = $remoteIp;
    }

    $curl = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    if ($curl === false) {
        jsonResponse(['status' => 'Failed', 'message' => 'Human verification is temporarily unavailable. Please try again.'], 503);
    }

    $timeout = max(3, min(20, (int) envValue('TURNSTILE_TIMEOUT_SECONDS', 8)));
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($raw === false || $status < 200 || $status >= 300) {
        error_log('Lambert Turnstile verification error: HTTP ' . $status . ' ' . $curlError);
        jsonResponse(['status' => 'Failed', 'message' => 'Human verification is temporarily unavailable. Please try again.'], 503);
    }

    try {
        $result = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        jsonResponse(['status' => 'Failed', 'message' => 'Human verification is temporarily unavailable. Please try again.'], 503);
    }

    if (!is_array($result) || empty($result['success'])) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'We could not verify this submission. Please try again.',
            'field' => 'turnstile_token',
        ], 422);
    }

    $allowedHostnames = configuredTurnstileHostnames();
    if ($allowedHostnames !== []) {
        $hostname = strtolower(trim((string) ($result['hostname'] ?? '')));
        if ($hostname === '' || !in_array($hostname, $allowedHostnames, true)) {
            error_log('Lambert Turnstile hostname mismatch: ' . $hostname);
            jsonResponse([
                'status' => 'Failed',
                'message' => 'We could not verify this submission. Please try again.',
                'field' => 'turnstile_token',
            ], 422);
        }
    }

    return true;
}
