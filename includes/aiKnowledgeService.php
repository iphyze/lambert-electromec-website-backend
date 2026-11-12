<?php

declare(strict_types=1);

require_once __DIR__ . '/aiConfig.php';
require_once __DIR__ . '/contentService.php';

function aiKnowledgeLocalizedPath(string $path, string $locale): string
{
    $path = '/' . ltrim(trim($path), '/');
    if ($locale !== 'fr') {
        return $path;
    }

    if ($path === '/fr' || str_starts_with($path, '/fr/')) {
        return $path;
    }

    return '/fr' . $path;
}

function aiKnowledgeSearchTokens(string $query, string $locale): array
{
    $query = mb_strtolower($query);
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-&\'’]{1,}/u', $query, $matches);

    $stopWords = $locale === 'fr'
        ? [
            'avec', 'dans', 'des', 'les', 'pour', 'une', 'un', 'sur', 'que', 'qui', 'quoi', 'est', 'sont', 'nous', 'vous', 'leur', 'leurs', 'lambert', 'electromec', 'limited', 'ltd', 'entreprise', 'société',
            // Pronouns, auxiliaries and generic verbs carry no Lambert meaning and
            // must never make an unrelated question look relevant.
            'le', 'la', 'du', 'de', 'au', 'aux', 'et', 'ou', 'en', 'ce', 'ces', 'cette', 'il', 'elle', 'ils', 'on',
            'tu', 'toi', 'ton', 'ta', 'tes', 'je', 'moi', 'mon', 'ma', 'mes', 'votre', 'vos', 'notre', 'nos',
            'être', 'etre', 'faire', 'fait', 'faites', 'peux', 'pouvez', 'peut', 'comment', 'pourquoi', 'quel', 'quelle', 'quels', 'quelles',
            'penses', 'pensez', 'explique', 'expliquez', 'dis', 'dites', 'donne', 'donnez', 'parle', 'parlez', 'moi', 'svp', 'plaît', 'plait',
        ]
        : [
            'with', 'from', 'that', 'this', 'what', 'which', 'where', 'when', 'have', 'has', 'does', 'about', 'show', 'tell', 'please', 'me', 'lambert', 'electromec', 'limited', 'ltd', 'company', 'provide', 'provides',
            // Pronouns, auxiliaries and generic verbs carry no Lambert meaning and
            // must never make an unrelated question look relevant.
            'the', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'by', 'as', 'an', 'it', 'its', 'is', 'are', 'was', 'were', 'be', 'been',
            'you', 'your', 'yours', 'we', 'us', 'our', 'ours', 'my', 'mine', 'they', 'them', 'their', 'he', 'she', 'his', 'her',
            'do', 'did', 'done', 'can', 'could', 'would', 'should', 'will', 'shall', 'may', 'might', 'how', 'why', 'who', 'whom', 'whose',
            'think', 'explain', 'know', 'give', 'want', 'need', 'like', 'any', 'some', 'there', 'here', 'just', 'also', 'more', 'much', 'many',
        ];

    $tokens = [];
    foreach ($matches[0] ?? [] as $token) {
        $token = trim((string) $token, "-'’&");
        if (mb_strlen($token) < 2 || preg_match('/\p{L}/u', $token) !== 1 || in_array($token, $stopWords, true)) {
            continue;
        }
        if (!in_array($token, $tokens, true)) {
            $tokens[] = $token;
        }
        if (count($tokens) >= aiKnowledgeSearchTokenLimit()) {
            break;
        }
    }

    return $tokens;
}

/**
 * Simple plural handling so "projects" also finds "project", "hospitals"
 * finds "hospital" and "countries" finds "country".
 *
 * @return list<string>
 */
function aiKnowledgeTermVariants(string $term): array
{
    $variants = [$term];
    $length = mb_strlen($term);
    if ($length >= 5 && mb_substr($term, -3) === 'ies') {
        $variants[] = mb_substr($term, 0, -3) . 'y';
    } elseif ($length >= 4 && mb_substr($term, -1) === 's' && mb_substr($term, -2) !== 'ss') {
        $variants[] = mb_substr($term, 0, -1);
    }

    return $variants;
}

/** Match entire Unicode words; long tokens can match the beginning of a word. */
function aiKnowledgeTermMatches(string $haystack, string $term, bool $prefix = false): bool
{
    if ($term === '' || preg_match('/\p{L}/u', $term) !== 1) {
        return false;
    }

    foreach (aiKnowledgeTermVariants($term) as $variant) {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($variant, '/')
            . ($prefix && mb_strlen($variant) >= 5 ? '[\p{L}\p{N}]*' : '')
            . '(?![\p{L}\p{N}])/iu';
        if (preg_match($pattern, $haystack) === 1) {
            return true;
        }
    }

    return false;
}

function aiKnowledgeScore(string $query, array $tokens, string $title, string $content): int
{
    $score = 0;

    // Full query matches must also respect word boundaries. In particular,
    // "story" must not match "history", and digits alone have no relevance.
    if (aiKnowledgeTermMatches($title, trim($query))) {
        $score += 40;
    }
    if (aiKnowledgeTermMatches($content, trim($query))) {
        $score += 20;
    }

    foreach ($tokens as $token) {
        $token = trim((string) $token);
        if (aiKnowledgeTermMatches($title, $token, true)) {
            $score += 12;
        }
        if (aiKnowledgeTermMatches($content, $token, true)) {
            $score += 4;
        }
    }

    return $score;
}

/** Questions about where Lambert is or how to reach it (EN/FR). */
function aiKnowledgeContactIntent(string $query): bool
{
    return preg_match(
        '/(?<![\p{L}\p{N}])(?:address|addresses|located|location|locations|where are you|where is your|offices?|head office|headquarters|phone|telephone|phone number|number|whatsapp|email|e-mail|contact|reach you|call you'
        . '|adresse|adresses|situé|située|situés|où êtes-vous|où se trouve|où se trouvent|bureaux?|siège|téléphone|numéro|courriel|contacter|joindre)(?![\p{L}\p{N}])/iu',
        $query
    ) === 1;
}

/**
 * Points added to the approved company pages that answer common questions
 * whose wording never appears in those pages. For example "Which countries
 * do you operate in?" is answered by the office list and company profile,
 * neither of which contains the word "countries".
 *
 * @return array<string, int> keyed by 'key:<knowledge_key>' or 'section:<section_key>'
 */
function aiKnowledgeIntentBoosts(string $query): array
{
    $boosts = [];
    $add = static function (array $targets, int $points) use (&$boosts): void {
        foreach ($targets as $target) {
            $boosts[$target] = max($boosts[$target] ?? 0, $points);
        }
    };
    $matches = static fn (string $pattern): bool => preg_match($pattern, $query) === 1;

    // Where Lambert is / which countries it works in.
    if ($matches('/(?<![\p{L}\p{N}])(?:countr(?:y|ies)|operate|operates|operating|operations|presence|present|regions?|territor(?:y|ies)|where (?:do|does|is|are)|locations?|located'
        . '|pays|opérez|opère|opérons|présence|présents?|régions?|où (?:êtes|est|se trouve|travaillez))(?![\p{L}\p{N}])/iu')) {
        $add(['section:contact', 'key:about.profile'], 24);
    }

    // How to reach Lambert.
    if (aiKnowledgeContactIntent($query)) {
        $add(['section:contact'], 16);
    }

    // History, age and origin of the company.
    if ($matches('/(?<![\p{L}\p{N}])(?:history|founded|established|origins?|heritage|how long|since when|began|started|in business|years of experience|how old|canadian|nigerian company|ghanaian company|background'
        . '|histoire|fondée?|créée?|origine|héritage|depuis quand|combien d[\x27’]années)(?![\p{L}\p{N}])/iu')) {
        $add(['key:about.history', 'key:about.profile'], 20);
    }

    // "What does Lambert do?" / "Who is Lambert?" / "Tell me about Lambert".
    // Only whole-company questions: "Tell me about Lambert's history" is left
    // to the history pages rather than the profile.
    if ($matches('/(?:what (?:does|do) (?:lambert|you|your company)(?: electromec)? do'
        . '|(?:who is|who are|what is|about|tell me about|describe) (?:lambert|you|your company|the company)(?: electromec)?(?: limited| ltd)?[\s?.!]*$'
        . '|overview|que fait lambert|que faites-vous|qui est lambert|qui êtes-vous|présentation de lambert)/iu')) {
        $add(['key:about.profile', 'key:corporate.solutions'], 20);
    }

    return $boosts;
}

function aiKnowledgeStaticEntries(mysqli $conn, string $query, string $locale, array $tokens): array
{
    $stmt = $conn->prepare(
        "SELECT id, knowledge_key, section_key, title, content, source_path, display_order
         FROM ai_knowledge_entries
         WHERE locale = ? AND status = 'active'
         ORDER BY display_order ASC, id ASC"
    );
    $stmt->bind_param('s', $locale);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    $intentBoosts = aiKnowledgeIntentBoosts($query);

    while ($row = $result->fetch_assoc()) {
        $searchable = implode(' ', [
            (string) $row['knowledge_key'],
            (string) $row['section_key'],
            (string) $row['content'],
        ]);
        $score = aiKnowledgeScore($query, $tokens, (string) $row['title'], $searchable);
        // "Where are you located?" / "What is your address?" never contain the
        // office addresses themselves, so point those questions at the office
        // and contact entries explicitly.
        $score += (int) ($intentBoosts['key:' . (string) $row['knowledge_key']] ?? 0);
        $score += (int) ($intentBoosts['section:' . (string) $row['section_key']] ?? 0);
        if ($score <= 0) {
            continue;
        }

        $items[] = [
            'type' => 'approved_knowledge',
            'id' => (int) $row['id'],
            'key' => (string) $row['knowledge_key'],
            'title' => (string) $row['title'],
            'content' => (string) $row['content'],
            'url' => aiKnowledgeLocalizedPath((string) $row['source_path'], $locale),
            'metadata' => [
                'section' => (string) $row['section_key'],
                'locale' => $locale,
            ],
            'score' => $score,
        ];
    }
    $stmt->close();

    usort($items, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($items, 0, aiKnowledgePerSourceLimit());
}

function aiKnowledgeLikeCondition(array $columns, array $tokens, string &$types, array &$values): string
{
    if ($tokens === []) {
        return '1 = 0';
    }

    $parts = [];
    foreach ($tokens as $token) {
        // Search for the singular form so "hospitals" still finds "Hospital".
        $variants = aiKnowledgeTermVariants((string) $token);
        $needle = '%' . end($variants) . '%';
        foreach ($columns as $column) {
            $parts[] = "LOWER({$column}) LIKE ?";
            $types .= 's';
            $values[] = $needle;
        }
    }

    return '(' . implode(' OR ', $parts) . ')';
}

function aiKnowledgeProjectEntries(mysqli $conn, string $query, string $locale, array $tokens): array
{
    if ($tokens === []) {
        return [];
    }

    $title = $locale === 'fr' ? "COALESCE(NULLIF(p.title_fr, ''), p.title_en)" : 'p.title_en';
    $summary = $locale === 'fr' ? "COALESCE(NULLIF(p.summary_fr, ''), p.summary_en)" : 'p.summary_en';
    $description = $locale === 'fr' ? "COALESCE(NULLIF(p.description_fr, ''), p.description_en)" : 'p.description_en';
    $location = $locale === 'fr' ? "COALESCE(NULLIF(p.location_fr, ''), p.location_en)" : 'p.location_en';
    $workDone = $locale === 'fr' ? "COALESCE(NULLIF(p.work_done_fr, ''), p.work_done_en)" : 'p.work_done_en';
    $division = $locale === 'fr' ? "COALESCE(NULLIF(d.name_fr, ''), d.name_en)" : 'd.name_en';

    $types = '';
    $values = [];
    $search = aiKnowledgeLikeCondition([$title, $summary, $description, $location, $workDone, $division], $tokens, $types, $values);
    $candidateLimit = max(aiKnowledgePerSourceLimit() * 5, 20);
    $types .= 'i';
    $values[] = $candidateLimit;

    $sql = "SELECT p.id, p.slug, {$title} AS title, {$summary} AS summary,
                   {$description} AS description, {$location} AS location,
                   {$workDone} AS work_done, p.project_year,
                   d.division_key, d.slug AS division_slug, {$division} AS division_name
            FROM projects p
            INNER JOIN divisions d ON d.id = p.division_id
            WHERE p.status = 'published'
              AND (p.published_at IS NULL OR p.published_at <= NOW())
              AND {$search}
            ORDER BY p.featured DESC, COALESCE(p.published_at, p.created_at) DESC, p.id DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    executePrepared($stmt, $types, $values);
    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $content = implode("\n\n", array_values(array_filter([
            (string) ($row['summary'] ?? ''),
            (string) ($row['description'] ?? ''),
            (string) ($row['location'] ?? ''),
            (string) ($row['work_done'] ?? ''),
            (string) ($row['project_year'] ?? ''),
            (string) ($row['division_name'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '')));
        $score = aiKnowledgeScore($query, $tokens, (string) $row['title'], $content);
        if ($score <= 0) {
            continue;
        }

        $items[] = [
            'type' => 'project',
            'id' => (int) $row['id'],
            'key' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'content' => $content,
            'url' => aiKnowledgeLocalizedPath('/projects/' . rawurlencode((string) $row['slug']), $locale),
            'metadata' => [
                'division_key' => (string) $row['division_key'],
                'division_slug' => (string) $row['division_slug'],
                'division_name' => (string) $row['division_name'],
                'location' => (string) ($row['location'] ?? ''),
                'project_year' => $row['project_year'] ?: null,
                'locale' => $locale,
            ],
            'score' => $score,
        ];
    }
    $stmt->close();

    usort($items, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($items, 0, aiKnowledgePerSourceLimit());
}

function aiKnowledgeFacilityEntries(mysqli $conn, string $query, string $locale, array $tokens): array
{
    if ($tokens === []) {
        return [];
    }

    $title = $locale === 'fr' ? "COALESCE(NULLIF(title_fr, ''), title_en)" : 'title_en';
    $description = $locale === 'fr' ? "COALESCE(NULLIF(description_fr, ''), description_en)" : 'description_en';
    $workDone = $locale === 'fr' ? "COALESCE(NULLIF(work_done_fr, ''), work_done_en)" : 'work_done_en';

    $types = '';
    $values = [];
    $search = aiKnowledgeLikeCondition([$title, $description, $workDone], $tokens, $types, $values);
    $candidateLimit = max(aiKnowledgePerSourceLimit() * 5, 20);
    $types .= 'i';
    $values[] = $candidateLimit;

    $sql = "SELECT id, slug, {$title} AS title, {$description} AS description, {$workDone} AS work_done
            FROM facility_projects
            WHERE status = 'published'
              AND (published_at IS NULL OR published_at <= NOW())
              AND {$search}
            ORDER BY display_order ASC, COALESCE(published_at, created_at) DESC, id ASC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    executePrepared($stmt, $types, $values);
    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $content = implode("\n\n", array_values(array_filter([
            (string) ($row['description'] ?? ''),
            (string) ($row['work_done'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '')));
        $score = aiKnowledgeScore($query, $tokens, (string) $row['title'], $content);
        if ($score <= 0) {
            continue;
        }

        $items[] = [
            'type' => 'facility_project',
            'id' => (int) $row['id'],
            'key' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'content' => $content,
            'url' => aiKnowledgeLocalizedPath('/facility-management', $locale),
            'metadata' => ['locale' => $locale],
            'score' => $score,
        ];
    }
    $stmt->close();

    usort($items, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($items, 0, aiKnowledgePerSourceLimit());
}

function aiKnowledgeMediaEntries(mysqli $conn, string $query, string $locale, array $tokens): array
{
    if ($tokens === []) {
        return [];
    }

    $title = $locale === 'fr' ? "COALESCE(NULLIF(m.title_fr, ''), m.title_en)" : 'm.title_en';
    $description = $locale === 'fr' ? "COALESCE(NULLIF(m.description_fr, ''), m.description_en)" : 'm.description_en';
    $category = $locale === 'fr' ? "COALESCE(NULLIF(c.name_fr, ''), c.name_en)" : 'c.name_en';

    $types = '';
    $values = [];
    $search = aiKnowledgeLikeCondition([$title, $description, 'm.reference', $category], $tokens, $types, $values);
    $candidateLimit = max(aiKnowledgePerSourceLimit() * 5, 20);
    $types .= 'i';
    $values[] = $candidateLimit;

    $sql = "SELECT m.id, m.slug, m.reference, {$title} AS title, {$description} AS description,
                   m.file_path, c.slug AS category_slug, {$category} AS category_name
            FROM media_items m
            INNER JOIN media_categories c ON c.id = m.category_id
            WHERE m.status = 'published'
              AND (m.published_at IS NULL OR m.published_at <= NOW())
              AND c.status = 'active'
              AND {$search}
            ORDER BY m.featured DESC, COALESCE(m.published_at, m.created_at) DESC, m.id DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    executePrepared($stmt, $types, $values);
    $result = $stmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $content = implode("\n\n", array_values(array_filter([
            (string) ($row['description'] ?? ''),
            (string) ($row['reference'] ?? ''),
            (string) ($row['category_name'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '')));
        $score = aiKnowledgeScore($query, $tokens, (string) $row['title'], $content);
        if ($score <= 0) {
            continue;
        }

        $items[] = [
            'type' => 'media',
            'id' => (int) $row['id'],
            'key' => (string) $row['slug'],
            'title' => (string) $row['title'],
            'content' => $content,
            'url' => aiKnowledgeLocalizedPath('/media-center', $locale),
            'metadata' => [
                'reference' => (string) ($row['reference'] ?? ''),
                'category_slug' => (string) $row['category_slug'],
                'category_name' => (string) $row['category_name'],
                'file_url' => publicAssetUrl((string) $row['file_path']),
                'locale' => $locale,
            ],
            'score' => $score,
        ];
    }
    $stmt->close();

    usort($items, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($items, 0, aiKnowledgePerSourceLimit());
}

function aiKnowledgeDivisionEntries(mysqli $conn, string $query, string $locale, array $tokens): array
{
    $name = $locale === 'fr' ? "COALESCE(NULLIF(name_fr, ''), name_en)" : 'name_en';
    $result = $conn->query(
        "SELECT id, division_key, slug, {$name} AS name
         FROM divisions
         WHERE status = 'active'
         ORDER BY display_order ASC, id ASC"
    );

    $genericTriggers = $locale === 'fr'
        ? ['division', 'divisions', 'activité', 'activités', 'service', 'services']
        : ['division', 'divisions', 'capability', 'capabilities', 'service', 'services'];
    $genericQuery = count(array_intersect($tokens, $genericTriggers)) > 0;
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $nameText = (string) $row['name'];
        $score = aiKnowledgeScore($query, $tokens, $nameText, (string) $row['division_key']);
        if ($score <= 0 && !$genericQuery) {
            continue;
        }
        if ($genericQuery && $score <= 0) {
            $score = 2;
        }

        $items[] = [
            'type' => 'division',
            'id' => (int) $row['id'],
            'key' => (string) $row['division_key'],
            'title' => $nameText,
            'content' => $nameText,
            'url' => aiKnowledgeLocalizedPath('/divisions', $locale),
            'metadata' => [
                'slug' => (string) $row['slug'],
                'locale' => $locale,
            ],
            'score' => $score,
        ];
    }

    usort($items, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($items, 0, aiKnowledgePerSourceLimit());
}

function retrieveApprovedAiKnowledge(mysqli $conn, mixed $rawQuery, string $rawLocale = 'en'): array
{
    $query = validatedAiKnowledgeQuery($rawQuery);
    $locale = aiKnowledgeLocale($rawLocale);
    $tokens = aiKnowledgeSearchTokens($query, $locale);

    // A purely numeric/operator query has no searchable Lambert concept.
    // Avoid even querying the database for "1 + 2" or similar inputs.
    if (preg_match('/\p{L}/u', $query) !== 1) {
        return [
            'query' => $query,
            'locale' => $locale,
            'tokens' => [],
            'sources' => [],
            'counts' => [
                'approved_knowledge' => 0,
                'projects' => 0,
                'facility_projects' => 0,
                'media' => 0,
                'divisions' => 0,
            ],
        ];
    }

    $groups = [
        'approved_knowledge' => aiKnowledgeStaticEntries($conn, $query, $locale, $tokens),
        'projects' => aiKnowledgeProjectEntries($conn, $query, $locale, $tokens),
        'facility_projects' => aiKnowledgeFacilityEntries($conn, $query, $locale, $tokens),
        'media' => aiKnowledgeMediaEntries($conn, $query, $locale, $tokens),
        'divisions' => aiKnowledgeDivisionEntries($conn, $query, $locale, $tokens),
    ];

    $sources = [];
    foreach ($groups as $group) {
        array_push($sources, ...$group);
    }

    usort($sources, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return strcmp((string) $a['type'], (string) $b['type']);
        }
        return $b['score'] <=> $a['score'];
    });
    $sources = array_slice($sources, 0, aiKnowledgeResultLimit());

    return [
        'query' => $query,
        'locale' => $locale,
        'tokens' => $tokens,
        'sources' => $sources,
        'counts' => array_map('count', $groups),
    ];
}
