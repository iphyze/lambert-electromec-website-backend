<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function contentString(mixed $value, int $maxLength, bool $required = false, string $field = 'Field'): string
{
    if (!is_string($value) && !is_numeric($value)) {
        if ($required) {
            jsonResponse(['status' => 'Failed', 'message' => $field . ' is required.'], 422);
        }
        return '';
    }

    $value = trim((string) $value);
    if ($required && $value === '') {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' is required.'], 422);
    }

    if (mb_strlen($value) > $maxLength) {
        jsonResponse([
            'status' => 'Failed',
            'message' => $field . ' must not exceed ' . $maxLength . ' characters.',
        ], 422);
    }

    return $value;
}

function positiveInt(mixed $value, string $field = 'ID'): int
{
    $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($int === false) {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' is invalid.'], 422);
    }
    return (int) $int;
}

function optionalPositiveInt(mixed $value, string $field = 'ID'): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return positiveInt($value, $field);
}

function booleanFlag(mixed $value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || ctype_digit((string) $value)) {
        return (int) $value === 1;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function appTimeZone(): DateTimeZone
{
    try {
        return new DateTimeZone((string) envValue('APP_TIMEZONE', 'Africa/Lagos'));
    } catch (Throwable) {
        return new DateTimeZone('Africa/Lagos');
    }
}

function normalizeDateTime(mixed $value, string $field): ?string
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }

    $raw = trim((string) $value);
    if (strlen($raw) > 40) {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' is invalid.'], 422);
    }

    try {
        $date = new DateTimeImmutable($raw, appTimeZone());
        return $date->setTimezone(appTimeZone())->format('Y-m-d H:i:s');
    } catch (Throwable) {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' is invalid.'], 422);
    }
}

function slugifyContent(string $value, string $fallback = 'content'): string
{
    $value = trim(mb_strtolower($value));
    if ($value === '') {
        return $fallback;
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }

    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = preg_replace('/-+/', '-', trim($value, '-')) ?? '';
    return substr($value !== '' ? strtolower($value) : $fallback, 0, 220);
}

function executePrepared(mysqli_stmt $stmt, string $types = '', array $values = []): void
{
    if ($types === '') {
        $stmt->execute();
        return;
    }

    if (strlen($types) !== count($values)) {
        throw new InvalidArgumentException('Prepared parameter count mismatch.');
    }

    $references = [];
    foreach ($values as $index => $value) {
        $values[$index] = $value;
        $references[$index] = &$values[$index];
    }

    array_unshift($references, $types);
    $stmt->bind_param(...$references);
    $stmt->execute();
}

function requestedLocale(): string
{
    $lang = strtolower(trim((string) ($_GET['lang'] ?? 'en')));
    return $lang === 'fr' ? 'fr' : 'en';
}

function paginationInput(int $defaultLimit = 12, int $maxLimit = 50): array
{
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $limit = filter_var($_GET['limit'] ?? $defaultLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    $page = $page === false ? 1 : (int) $page;
    $limit = $limit === false ? $defaultLimit : min((int) $limit, $maxLimit);

    return [$page, $limit, ($page - 1) * $limit];
}

function publicApiUrl(): string
{
    return rtrim(trim((string) envValue('PUBLIC_API_URL', '')), '/');
}

function publicAssetUrl(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (preg_match('#^(?:https?:)?//#i', $value) === 1) {
        return $value;
    }

    $base = publicApiUrl();
    return $base === '' ? '/' . ltrim($value, '/') : $base . '/' . ltrim($value, '/');
}

function paginationPayload(int $page, int $limit, int $total): array
{
    $totalPages = max(1, (int) ceil($total / max(1, $limit)));
    return [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
        'has_previous' => $page > 1,
        'has_next' => $page < $totalPages,
    ];
}
