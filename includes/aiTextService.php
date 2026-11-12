<?php

declare(strict_types=1);

/**
 * Confirmed malformed joins observed in Lambert AI output.
 * Replacements only INSERT a space; they never remove or rewrite wording.
 */
function aiTextKnownSpacingReplacements(): array
{
    return [
        'andexpertise' => 'and expertise',
        'andfacility' => 'and facility',
        'andfacilities' => 'and facilities',
        'andservices' => 'and services',
        'andprojects' => 'and projects',
        'andsystems' => 'and systems',
        'andsolutions' => 'and solutions',
        'andmaintenance' => 'and maintenance',
        'andmanagement' => 'and management',
        'andconstruction' => 'and construction',
        'andengineering' => 'and engineering',
        'andpower' => 'and power',
        'anddata' => 'and data',
        'andinformation' => 'and information',
        'andequipment' => 'and equipment',
        'andimplemented' => 'and implemented',
        'maintenanceand' => 'maintenance and',
        'ofwork' => 'of work',
        'ofservice' => 'of service',
        'ofservices' => 'of services',
        'ofspecialisation' => 'of specialisation',
        'ofspecialization' => 'of specialization',
        'ofexpertise' => 'of expertise',
        'ofscope' => 'of scope',
        'ofproject' => 'of project',
        'ofprojects' => 'of projects',
        'ofexperience' => 'of experience',
        'ofinformation' => 'of information',
        'ofequipment' => 'of equipment',
        'ofmaintenance' => 'of maintenance',
        'ofmanagement' => 'of management',
        'ofconstruction' => 'of construction',
        'ofcapability' => 'of capability',
        'ofcapabilities' => 'of capabilities',
        'ensurethat' => 'ensure that',
        'propertymanagement' => 'property management',
        'facilitymanagement' => 'facility management',
        'pipedservices' => 'piped services',
        'wellas' => 'well as',
        'stepstaken' => 'steps taken',
        'useof' => 'use of',
    ];
}

/**
 * Build exact case variants so strtr() can be used instead of regex/PCRE.
 */
function aiTextExactSpacingMap(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $map = [];
    foreach (aiTextKnownSpacingReplacements() as $joined => $spaced) {
        $map[$joined] = $spaced;
        $map[ucfirst($joined)] = ucfirst($spaced);
        $map[strtoupper($joined)] = strtoupper($spaced);
    }

    return $map;
}

function aiTextRepairKnownSpacing(string $value): string
{
    return strtr($value, aiTextExactSpacingMap());
}

/**
 * Convert only explicitly known UTF-8 separator bytes to ASCII spaces.
 * This function never includes U+0020 (ordinary space) in its search list.
 */
function aiTextNormalizeUnicodeSpacing(string $value): string
{
    $beforeSpaces = substr_count($value, ' ');

    $candidate = str_replace([
        "\xC2\xA0",       // U+00A0 NO-BREAK SPACE
        "\xE2\x80\x87", // U+2007 FIGURE SPACE
        "\xE2\x80\x89", // U+2009 THIN SPACE
        "\xE2\x80\x8A", // U+200A HAIR SPACE
        "\xE2\x80\x8B", // U+200B ZERO WIDTH SPACE
        "\xE2\x80\x8C", // U+200C ZERO WIDTH NON-JOINER
        "\xE2\x80\x8D", // U+200D ZERO WIDTH JOINER
        "\xE2\x80\xAF", // U+202F NARROW NO-BREAK SPACE
        "\xE2\x81\xA0", // U+2060 WORD JOINER
        "\xE3\x80\x80", // U+3000 IDEOGRAPHIC SPACE
        "\xEF\xBB\xBF", // U+FEFF BOM / ZERO WIDTH NBSP
    ], ' ', $value);

    // Safety invariant: this operation must never reduce ordinary spaces.
    if (substr_count($candidate, ' ') < $beforeSpaces) {
        return $value;
    }

    return $candidate;
}

/**
 * Minimal inline normalisation. It only converts line/control separators to
 * spaces and repairs confirmed joined words. It does not collapse whitespace.
 */
function aiTextNormalizeInline(string $value): string
{
    $originalSpaces = substr_count($value, ' ');

    $candidate = str_replace(["\r\n", "\r", "\n", "\t", "\f", "\v"], ' ', $value);
    $candidate = aiTextNormalizeUnicodeSpacing($candidate);
    $candidate = aiTextRepairKnownSpacing($candidate);

    // The normalizer is allowed to add spaces, never remove existing ones.
    if (substr_count($candidate, ' ') < $originalSpaces) {
        $candidate = aiTextRepairKnownSpacing(str_replace(["\r\n", "\r", "\n", "\t", "\f", "\v"], ' ', $value));
    }

    return trim($candidate);
}

/**
 * Minimal multiline normalisation. Preserve every ordinary space exactly.
 */
function aiTextNormalizeMultiline(string $value): string
{
    $originalSpaces = substr_count($value, ' ');

    $candidate = str_replace(["\r\n", "\r"], "\n", $value);
    $candidate = aiTextNormalizeUnicodeSpacing($candidate);
    $candidate = aiTextRepairKnownSpacing($candidate);

    if (substr_count($candidate, ' ') < $originalSpaces) {
        $candidate = aiTextRepairKnownSpacing(str_replace(["\r\n", "\r"], "\n", $value));
    }

    return trim($candidate);
}

/**
 * Final response boundary used immediately before an answer leaves PHP.
 */
function aiTextNormalizeFinalAnswer(string $value): string
{
    return aiTextNormalizeMultiline($value);
}

function aiTextKnownJoinedSpacingMatches(string $value): array
{
    $lower = mb_strtolower(aiTextNormalizeUnicodeSpacing($value), 'UTF-8');
    $matches = [];

    foreach (array_keys(aiTextKnownSpacingReplacements()) as $joined) {
        if (mb_strpos($lower, $joined, 0, 'UTF-8') !== false) {
            $matches[] = $joined;
        }
    }

    return array_values(array_unique($matches));
}
