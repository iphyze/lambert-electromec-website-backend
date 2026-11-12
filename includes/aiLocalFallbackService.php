<?php

declare(strict_types=1);

require_once __DIR__ . '/aiConfig.php';
require_once __DIR__ . '/aiKnowledgeService.php';
require_once __DIR__ . '/aiTextService.php';

function aiLocalNormalizeText(string $value): string
{
    return aiTextNormalizeInline($value);
}

function aiLocalExcerpt(string $value, ?int $maxChars = null): string
{
    $text = aiLocalNormalizeText($value);
    $maxChars ??= aiAssistantFallbackExcerptChars();

    if ($text === '' || mb_strlen($text) <= $maxChars) {
        return $text;
    }

    $candidate = mb_substr($text, 0, $maxChars);
    $lastBoundary = -1;
    foreach (['. ', '! ', '? ', '; '] as $boundary) {
        $position = mb_strrpos($candidate, $boundary);
        if ($position !== false) {
            $lastBoundary = max($lastBoundary, (int) $position + 1);
        }
    }

    if ($lastBoundary >= (int) floor($maxChars * 0.55)) {
        return rtrim(mb_substr($candidate, 0, $lastBoundary));
    }

    $lastSpace = mb_strrpos($candidate, ' ');
    if ($lastSpace !== false && $lastSpace >= (int) floor($maxChars * 0.65)) {
        $candidate = mb_substr($candidate, 0, (int) $lastSpace);
    }

    return rtrim($candidate, " \t\n\r\0\x0B,;:-") . '…';
}

function aiLocalGreetingOrHelp(string $message, string $locale): ?string
{
    $normalized = mb_strtolower(aiLocalNormalizeText($message));

    $greetings = $locale === 'fr'
        ? ['bonjour', 'bonsoir', 'salut', 'hello', 'bonjour lambert']
        : ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'hello lambert'];

    foreach ($greetings as $greeting) {
        if ($normalized === $greeting || $normalized === $greeting . '!' || $normalized === $greeting . '.') {
            return $locale === 'fr'
                ? 'Bonjour. Je peux vous aider à consulter les services, divisions, projets publiés, Facility Management et coordonnées de Lambert Electromec.'
                : 'Hello. I can help you explore Lambert Electromec’s published services, divisions, projects, Facility Management and contact information.';
        }
    }

    $thanks = $locale === 'fr'
        ? ['merci', 'merci beaucoup']
        : ['thank you', 'thank you very much', 'thanks'];
    $goodbyes = $locale === 'fr'
        ? ['au revoir', 'à bientôt']
        : ['goodbye', 'bye', 'see you'];
    foreach ($thanks as $courtesy) {
        if ($normalized === $courtesy || $normalized === $courtesy . '!' || $normalized === $courtesy . '.') {
            return $locale === 'fr'
                ? 'Je vous en prie. N’hésitez pas à poser d’autres questions sur Lambert Electromec.'
                : 'You’re welcome. Feel free to ask more questions about Lambert Electromec.';
        }
    }
    foreach ($goodbyes as $courtesy) {
        if ($normalized === $courtesy || $normalized === $courtesy . '!' || $normalized === $courtesy . '.') {
            return $locale === 'fr'
                ? 'Au revoir. Nous restons à votre disposition pour toute question sur Lambert Electromec.'
                : 'Goodbye. We’re here if you have more questions about Lambert Electromec.';
        }
    }

    $wellbeing = $locale === 'fr'
        ? ['comment allez-vous', 'comment vas-tu', 'ça va', 'ca va']
        : ['how are you', 'how are you doing', 'how are you today', 'how are you doing today', "how's it going", 'how is it going'];
    foreach ($wellbeing as $courtesy) {
        if ($normalized === $courtesy || $normalized === $courtesy . '?' || $normalized === $courtesy . '!' || $normalized === $courtesy . '.') {
            return $locale === 'fr'
                ? 'Très bien, merci. Comment puis-je vous aider concernant Lambert Electromec ?'
                : 'I’m well, thank you. How can I help you with Lambert Electromec today?';
        }
    }

    // Whole-message match only: "Can you help me write a cover letter?" must not
    // receive this reply (it is refused by the scope check instead).
    $helpTriggers = $locale === 'fr'
        ? ['que pouvez-vous faire', 'comment pouvez-vous aider', 'comment pouvez-vous m’aider', "comment pouvez-vous m'aider", 'pouvez-vous m’aider', "pouvez-vous m'aider", 'aidez-moi', 'aide']
        : ['what can you do', 'how can you help', 'how can you help me', 'can you help me', 'help me', 'help', 'help me please'];

    foreach ($helpTriggers as $trigger) {
        if (preg_match('/^' . preg_quote($trigger, '/') . '[\s.!?]*$/iu', $normalized) === 1) {
            return $locale === 'fr'
                ? 'Je peux rechercher les informations Lambert approuvées et publiées concernant les services, divisions, projets, Facility Management, Media Center et coordonnées.'
                : 'I can search Lambert’s approved and published information about services, divisions, projects, Facility Management, Media Center and contact details.';
        }
    }

    return null;
}

function aiLocalContactIntent(string $message, string $locale): bool
{
    $normalized = mb_strtolower(aiLocalNormalizeText($message));
    $triggers = $locale === 'fr'
        ? ['contact', 'contacter', 'téléphone', 'telephone', 'email', 'e-mail', 'adresse', 'bureau', 'joindre']
        : ['contact', 'phone', 'telephone', 'email', 'e-mail', 'address', 'office', 'reach lambert'];

    foreach ($triggers as $trigger) {
        if (str_contains($normalized, $trigger)) {
            return true;
        }
    }

    return false;
}

function aiLocalProjectIntent(string $message, string $locale): bool
{
    $normalized = mb_strtolower(aiLocalNormalizeText($message));
    $triggers = $locale === 'fr'
        ? ['projet', 'projets', 'référence', 'références', 'expérience', 'portfolio', 'réalisation', 'réalisations']
        : ['project', 'projects', 'reference', 'references', 'experience', 'portfolio', 'worked on', 'case study', 'case studies'];

    foreach ($triggers as $trigger) {
        if (str_contains($normalized, $trigger)) {
            return true;
        }
    }

    return false;
}

function aiLocalProjectAnswer(array $sources, string $locale): ?array
{
    $projectSources = [];
    foreach ($sources as $index => $source) {
        if (($source['type'] ?? '') !== 'project') {
            continue;
        }
        $projectSources[] = ['index' => $index, 'source' => $source];
        if (count($projectSources) >= aiAssistantFallbackSourceLimit()) {
            break;
        }
    }

    if ($projectSources === []) {
        return null;
    }

    $parts = [];
    $sourceIds = [];
    foreach ($projectSources as $item) {
        $source = $item['source'];
        $metadata = is_array($source['metadata'] ?? null) ? $source['metadata'] : [];
        $details = array_values(array_filter([
            aiLocalNormalizeText((string) ($metadata['location'] ?? '')),
            aiLocalNormalizeText((string) ($metadata['project_year'] ?? '')),
            aiLocalNormalizeText((string) ($metadata['division_name'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));

        $entry = aiLocalNormalizeText((string) ($source['title'] ?? ''));
        if ($details !== []) {
            $entry .= ' — ' . implode(', ', $details);
        }
        $parts[] = $entry;
        $sourceIds[] = 'S' . ($item['index'] + 1);
    }

    $prefix = $locale === 'fr'
        ? 'Les projets publiés de Lambert correspondant à votre demande comprennent : '
        : 'Lambert’s published projects matching your request include: ';

    return [
        'answer' => $prefix . implode('; ', $parts) . '.',
        'source_ids' => $sourceIds,
        'should_contact' => false,
        'cannot_confirm' => false,
    ];
}

function aiLocalKnowledgeAnswer(array $sources, string $locale): ?array
{
    $selected = [];
    foreach ($sources as $index => $source) {
        if (!in_array((string) ($source['type'] ?? ''), ['approved_knowledge', 'division', 'project', 'facility_project', 'media'], true)) {
            continue;
        }

        $content = aiLocalExcerpt((string) ($source['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $selected[] = [
            'index' => $index,
            'title' => aiLocalNormalizeText((string) ($source['title'] ?? '')),
            'content' => $content,
        ];
        if (count($selected) >= aiAssistantFallbackSourceLimit()) {
            break;
        }
    }

    if ($selected === []) {
        return null;
    }

    $parts = [];
    $sourceIds = [];
    foreach ($selected as $item) {
        $label = $item['title'] !== '' ? $item['title'] . ': ' : '';
        $parts[] = $label . $item['content'];
        $sourceIds[] = 'S' . ($item['index'] + 1);
    }

    $prefix = $locale === 'fr'
        ? 'Selon les informations Lambert approuvées et publiées : '
        : 'Based on Lambert’s approved and published information: ';

    return [
        'answer' => $prefix . implode(' ', $parts),
        'source_ids' => $sourceIds,
        'should_contact' => false,
        'cannot_confirm' => false,
    ];
}

function createLambertLocalFallbackAssistantResponse(
    string $message,
    string $locale,
    array $sources,
    ?string $reason = null
): array {
    $greeting = aiLocalGreetingOrHelp($message, $locale);
    if ($greeting !== null) {
        return [
            'assistant' => [
                'answer' => $greeting,
                'source_ids' => [],
                'should_contact' => false,
                'cannot_confirm' => false,
            ],
            'provider' => aiLocalProviderMetadata($reason),
        ];
    }

    $response = null;
    if (aiLocalProjectIntent($message, $locale)) {
        $response = aiLocalProjectAnswer($sources, $locale);
    }
    if ($response === null) {
        $response = aiLocalKnowledgeAnswer($sources, $locale);
    }

    if ($response === null) {
        $contact = aiLocalContactIntent($message, $locale);
        $response = [
            'answer' => $locale === 'fr'
                ? 'Je ne peux pas confirmer cela à partir des informations Lambert approuvées et publiées. Veuillez contacter Lambert pour obtenir de l’aide.'
                : 'I cannot confirm that from Lambert’s approved and published information. Please contact Lambert for assistance.',
            'source_ids' => [],
            'should_contact' => true,
            'cannot_confirm' => true,
        ];
        if ($contact) {
            $response['answer'] = $locale === 'fr'
                ? 'Veuillez utiliser la page Contact de Lambert pour joindre l’équipe.'
                : 'Please use Lambert’s Contact page to reach the team.';
            $response['cannot_confirm'] = false;
        }
    } elseif (aiLocalContactIntent($message, $locale)) {
        $response['should_contact'] = true;
    }

    $response['answer'] = mb_substr(aiTextNormalizeFinalAnswer((string) $response['answer']), 0, aiAssistantAnswerMaxChars());

    return [
        'assistant' => $response,
        'provider' => aiLocalProviderMetadata($reason),
    ];
}

function aiLocalProviderMetadata(?string $reason = null): array
{
    return [
        'provider' => 'local',
        'model' => 'lambert-knowledge-fallback-v1',
        'response_id' => null,
        'text' => '',
        'usage' => [],
        'fallback' => true,
        'fallback_reason' => $reason !== null ? mb_substr($reason, 0, 100) : null,
    ];
}
