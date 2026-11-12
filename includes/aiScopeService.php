<?php

declare(strict_types=1);

require_once __DIR__ . '/aiKnowledgeService.php';

/** The single source of truth for both deterministic and model-rejected requests. */
function aiAssistantOutOfScopeMessage(string $locale): string
{
    return strtolower(trim($locale)) === 'fr'
        ? "Je suis désolé, mais je ne peux pas répondre à cette demande, car elle ne relève pas du champ d'activité de Lambert Electromec Limited. Je serai ravi de vous aider pour toute question concernant nos services, divisions, projets, Facility Management, Media Center ou coordonnées."
        : "I'm sorry, but I can't help with that request as it falls outside the scope of Lambert Electromec Limited. I'd be glad to assist with questions about our services, divisions, projects, Facility Management, Media Centre or contact information.";
}

/** Unicode-aware whole-word/phrase matching; never match "story" inside "history". */
function aiScopeHasPhrase(string $text, array $phrases): bool
{
    foreach ($phrases as $phrase) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
            return true;
        }
    }

    return false;
}

function aiScopeHasPattern(string $text, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Questions put to the company about what it does, has done or offers
 * ("Do you handle air conditioning?", "Have you worked on any hotels?",
 * "How do I apply for a job?"). Personal or opinion questions such as
 * "Do you love me?" or "Can you recommend a laptop?" do not match.
 */
function aiScopeIsCompanyServiceQuestion(string $text): bool
{
    return aiScopeHasPattern($text, [
        '/\b(?:do|does|did)\s+(?:you|your\s+(?:company|team|firm))\s+(?:also\s+)?(?:do|offer|provide|handle|install|build|construct|work|supply|maintain|have|undertake|operate|design|repair|service|accept|hire|deliver|execute|manage|carry out|specialise|specialize|cover)\b/iu',
        '/\b(?:have|has)\s+(?:you|your\s+(?:company|team|firm))\s+(?:ever\s+|already\s+)?(?:worked|done|completed|built|delivered|installed|executed|won|been|handled|maintained|designed|received|supplied)\b/iu',
        '/\b(?:can|could|will)\s+you\s+(?:also\s+)?(?:do|build|construct|install|handle|supply|provide|design|maintain|repair|deliver|execute|undertake|manage|service)\b/iu',
        '/\bare\s+you\s+(?:hiring|recruiting|certified|accredited|registered|licensed|available|present|operating|based|active)\b/iu',
        '/\bwhat\s+(?:awards|certifications|accreditations|clients|partners|sectors|industries|countries)\s+(?:have|do)\s+you\b/iu',
        '/\b(?:is there|are there)\s+(?:a|an|any)\s+(?:job|jobs|vacancy|vacancies|opening|openings|position|positions|internship|internships)\b/iu',
        '/\bhow\s+(?:do|can)\s+i\s+(?:apply|become a supplier|register as a supplier|send my cv|submit my cv|reach|contact|get in touch|request a quote|get a quote)\b/iu',
        '/\b(?:can|may|where can|where do)\s+i\s+(?:send|submit|upload|find|download|apply)\b/iu',
        '/\b(?:avez-vous|faites-vous|proposez-vous|offrez-vous|installez-vous|construisez-vous|travaillez-vous|intervenez-vous|réalisez-vous|recrutez-vous|êtes-vous\s+(?:certifiés?|présents?|basés?|agréés?))\b/iu',
        '/\bpouvez-vous\s+(?:installer|construire|réaliser|fournir|concevoir|entretenir|gérer|réparer)\b/iu',
        '/\bcomment\s+(?:postuler|devenir fournisseur|envoyer mon cv|vous contacter|vous joindre|obtenir un devis)\b/iu',
    ]);
}

/**
 * An anchor indicates interest in the company or its published areas of work.
 * Loose pronouns and city names are deliberately not used to override a clear
 * general-knowledge request (e.g. weather in Lagos).
 */
function aiScopeHasLambertAnchor(string $text, bool $strongOnly = false): bool
{
    $companyAndServices = [
        'lambert', 'lambert electromec', 'lambert somec', 'lem',
        'our company', 'your company', 'notre entreprise', 'votre entreprise',
        'our services', 'your services', 'nos services', 'vos services',
        'our divisions', 'your divisions', 'nos divisions', 'vos divisions',
        'our projects', 'your projects', 'nos projets', 'vos projets',
        'our history', 'your history', 'notre histoire', 'votre histoire',
        'our vision', 'your mission', 'notre vision', 'votre mission',
        'our offices', 'your offices', 'nos bureaux', 'vos bureaux',
        'our contacts', 'your contacts', 'vos coordonnées',
        // Lambert's own areas of work (specific enough to stand on their own).
        'facility management', 'facilities management', 'gestion des installations',
        'hvac', 'mep', 'epc', 'solar', 'solaire', 'renewable energy',
        'énergie renouvelable', 'énergies renouvelables', 'data centre', 'data centres', 'data center', 'data centers',
        'centre de données', 'maintenance', 'refinery', 'refineries', 'raffinerie', 'waltersmith',
        'electromechanical', 'electro-mechanical', 'electromécanique', 'iso', 'hse', 'csr', 'rse',
        'media centre', 'media center', 'centre média', 'centre médias',
        'indevco', 'phoenix energy', 'supplier', 'suppliers', 'code of conduct', 'fournisseur', 'fournisseurs',
        'careers', 'career', 'carrières', 'carrière', 'contact details', 'coordonnées',
        // Company facts visitors commonly ask about without naming Lambert.
        'address', 'located', 'where are you', 'headquarters', 'head office', 'founded', 'work for you',
        'hiring', 'vacancy', 'vacancies',
        'adresse', 'situé', 'située', 'situés', 'siège', 'fondée', 'fondé', 'recrutez', 'recrute',
        // Engineering terms from Lambert's divisions and services.
        'substation', 'substations', 'transformer', 'transformers', 'generator', 'generators',
        'kv', 'mw', 'mva', 'plumbing', 'cabling', 'telecom', 'telecoms', 'chiller', 'chillers',
        'bms', 'elv', 'cctv', 'fit-out', 'retrofit', 'oil and gas', 'oil & gas', 'gas turbine',
        'gas turbines', 'power plant', 'power plants',
        'poste de transformation', 'transformateur', 'groupe électrogène', 'groupes électrogènes',
        'plomberie', 'câblage', 'centrale électrique',
        'air conditioning', 'air-conditioning', 'fire alarm', 'fire alarms', 'fire fighting', 'firefighting',
        'fire suppression', 'access control', 'security systems', 'lighting', 'street lighting', 'lifts',
        'elevators', 'escalators', 'water treatment', 'sewage', 'effluent', 'solar panels', 'inverters',
        'climatisation', 'détection incendie', 'éclairage', 'ascenseurs', 'traitement des eaux',
    ];

    if (aiScopeHasPhrase($text, $companyAndServices)) {
        return true;
    }

    if (aiScopeIsCompanyServiceQuestion($text)) {
        return true;
    }

    // "Who is the CEO?" on Lambert's website means Lambert's CEO, but
    // "Who is the CEO of Apple?" does not.
    if (preg_match('/(?<![\p{L}\p{N}])(?:ceo|managing director|chairman|founder|directeur général|pdg)(?![\p{L}\p{N}])(?!\s+(?:of|at|de|du|d[\x27’])\s*(?!lambert))/iu', $text) === 1) {
        return true;
    }

    // Everyday words that only point to Lambert when the visitor addresses the
    // company directly: "How many years of experience do you have?" is a
    // Lambert question, "How many years ago did the war end?" is not.
    $addressesCompany = aiScopeHasPhrase($text, ['you', 'your', 'yours', 'we', 'us', 'our', 'vous', 'votre', 'vos', 'nous', 'notre', 'nos']);
    if ($addressesCompany && aiScopeHasPhrase($text, [
        'project', 'projects', 'division', 'divisions', 'service', 'services', 'staff', 'employees',
        'personnel', 'founder', 'leadership', 'director', 'reach', 'power', 'energy', 'engineering',
        'electrical', 'construction', 'certification', 'certified', 'career', 'careers', 'recruitment',
        'certifications', 'accreditation', 'accreditations', 'partners', 'partner',
        'contact', 'telephone', 'phone', 'number', 'numbers', 'email', 'e-mail', 'office', 'offices',
        'client', 'clients', 'building', 'buildings', 'factory', 'factories', 'experience', 'years',
        'history', 'people', 'team', 'job', 'jobs', 'location', 'locations', 'work in', 'operate in',
        'lagos', 'abuja', 'port harcourt', 'accra', 'abidjan', 'nigeria', 'nigéria', 'ghana',
        "côte d'ivoire", "cote d'ivoire", 'côte d’ivoire', 'ivory coast', 'west africa',
        'projet', 'projets', 'prestations', 'direction', 'ingénierie', 'électrique', 'certifié',
        'certifications', 'partenaires', 'recrutement', 'contacter', 'téléphone', 'numéro', 'bureau', 'bureaux',
        'bâtiment', 'bâtiments', 'usine', 'usines', 'expérience', 'années', 'ans', 'histoire',
        'équipe', 'emploi', 'emplois', 'personnes', 'travaillez', "afrique de l'ouest",
    ])) {
        return true;
    }

    if ($strongOnly) {
        return false;
    }

    return aiScopeHasPhrase($text, [
        'you', 'your', 'yours', 'we', 'us', 'our', 'vous', 'votre', 'vos', 'nous', 'notre', 'nos',
        'lagos', 'abuja', 'port harcourt', 'nigeria', 'nigéria', 'ghana', 'québec', 'quebec',
    ]);
}

/**
 * Arithmetic is identified by *intent*, not the mere presence of digits.
 * A company phone, ISO standard, shift coverage and plant capacity are not sums.
 */
function aiScopeLooksLikeCalculation(string $message, string $locale): bool
{
    $text = mb_strtolower(trim($message));
    // Only a strong (company/service) anchor can excuse numbers in a question.
    // "you", "your" and city names must not turn "Can you tell me 5 + 5?"
    // into a Lambert enquiry.
    $anchor = aiScopeHasLambertAnchor($text, true);

    // Arithmetic expressions: numerals joined by operators or an elementary equation.
    $expression = aiScopeHasPattern($text, [
        '/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?\s*(?:\+|\-|\*|\/|x|×|÷|\^|%|=)\s*\d+(?:[.,]\d+)?(?![\p{L}\p{N}])/iu',
        '/\b(?:x|y|z)\s*[+\-*\/=^]\s*\d+\s*=\s*\d+\b/iu',
    ]);

    // ISO numbers and 24/7 service coverage do not represent arithmetic.
    $withoutIdentifiers = preg_replace([
        '/\biso\s*\d+(?::\d+)?\b/iu',
        '/\b24\s*\/\s*7\b/u',
        '/\b\d+(?:[.,]\d+)?\s*(?:bpd|mw|kw|kv|mva)\b/iu',
        '/\b\d+\s*\/\s*\d+\s*(?:kv|mw)\b/iu',
        '/\+\d{1,3}(?:[\s-]+\d{2,5}){2,}\b/u',
    ], ' ', $text) ?? $text;
    $meaningfulExpression = $expression && aiScopeHasPattern($withoutIdentifiers, [
        '/(?<![\p{L}\p{N}])\d+(?:[.,]\d+)?\s*(?:\+|\-|\*|\/|x|×|÷|\^|%|=)\s*\d+(?:[.,]\d+)?(?![\p{L}\p{N}])/iu',
        '/\b(?:x|y|z)\s*[+\-*\/=^]\s*\d+\s*=\s*\d+\b/iu',
    ]);

    $commands = [
        '/\b(?:calculate|compute|solve|simplify|evaluate|convert|work out|how much is)\b/iu',
        '/\b(?:how much is|what is|what\x{2019}s|what\x27s)\s+\d+\s*(?:[+\-*\/x×÷^%=]|plus|minus|times)/iu',
        '/\b(?:square root(?: of)?|squared|cubed|to the power of|multiplied by|divided by|percent of|percentage of|puissance)\b/iu',
        '/\b(?:calcule|calculer|calculez|résous|resous|résoudre|convertis|convertir|combien font|racine carrée|multiplié par|multiplié par|divisé par|pour cent de)\b/iu',
        '/\b\d+\s*(?:plus|minus|times|fois|moins)\s*\d+\b/iu',
    ];
    $calculationCommand = aiScopeHasPattern($text, $commands);
    $unitConversion = aiScopeHasPattern($text, [
        '/\bhow many\s+(?:metres|meters|kilometres|kilometers|miles|feet|inches|grams|kilograms|litres|liters|pounds|yards)\s+(?:are\s+)?in\s+(?:a|an|one|\d+)\b/iu',
        '/\b(?:combien de|convertis|convertir)\s+(?:mètres|metres|kilomètres|kilometres|miles|pieds|litres|liters|grammes|kilogrammes)\b/iu',
        '/\b(?:usd|eur|gbp|naira|ngn|dollars?|euros?)\s+(?:to|en|vers|in)\s+(?:usd|eur|gbp|naira|ngn|dollars?|euros?)\b/iu',
    ]);

    // A bare sum is still a sum, even if it is prefaced by a courtesy or the
    // company name. Requests to perform a sum are never company facts.
    $explicitMathRequest = aiScopeHasPattern($text, [
        '/\b(?:calculate|compute|solve|evaluate|calcule|calculez|résous|resous|combien font|square root|racine carrée)\b/iu',
        '/\b(?:what is|what\x27s|what\x{2019}s|combien font)\s+\d+\s*[+\-*\/x×÷^%=]/iu',
    ]);

    if ($explicitMathRequest && ($meaningfulExpression || $calculationCommand)) {
        return true;
    }
    if ($anchor) {
        return false;
    }

    return $meaningfulExpression || $calculationCommand || $unitConversion;
}

/** Greetings, thanks, goodbyes and "how are you" (EN/FR). */
function aiScopeIsCourtesy(string $text): bool
{
    $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));

    return preg_match(
        '/^(?:(?:hi|hello|hey|good morning|good afternoon|good evening)(?: there| lambert)?'
        . '|thank you(?: very much| so much)?|thanks(?: a lot)?|goodbye|bye|see you(?: later)?'
        . '|how are you(?: doing)?(?: today)?|how\x27s it going|how is it going'
        . '|bonjour(?: lambert)?|bonsoir|salut|merci(?: beaucoup)?|au revoir|à bientôt|a bientot'
        . '|comment allez-vous|comment vas-tu|ça va|ca va)'
        . '(?:[,\s]+(?:hi|hello|thanks|merci|bonjour))?[.!?\s]*$/iu',
        $text
    ) === 1;
}

/** Reply for a courtesy message that the local greeting lists do not cover. */
function aiScopeCourtesyReply(string $text, string $locale): string
{
    $text = mb_strtolower($text);
    $fr = $locale === 'fr';

    if (preg_match('/\b(?:help|aide|aider|what can you do|what do you do|what can i ask|que pouvez-vous faire)\b/iu', $text) === 1) {
        return $fr
            ? 'Je peux rechercher les informations Lambert approuvées et publiées concernant les services, divisions, projets, Facility Management, Media Center et coordonnées.'
            : 'I can search Lambert’s approved and published information about services, divisions, projects, Facility Management, Media Centre and contact details.';
    }

    if (preg_match('/\b(?:thank|thanks|merci)\b/iu', $text) === 1) {
        return $fr
            ? 'Je vous en prie. N’hésitez pas à poser d’autres questions sur Lambert Electromec.'
            : 'You’re welcome. Feel free to ask more questions about Lambert Electromec.';
    }
    if (preg_match('/\b(?:bye|goodbye|see you|au revoir|bientôt|bientot)\b/iu', $text) === 1) {
        return $fr
            ? 'Au revoir. Nous restons à votre disposition pour toute question sur Lambert Electromec.'
            : 'Goodbye. We’re here if you have more questions about Lambert Electromec.';
    }
    if (preg_match('/\b(?:how are you|how\x27s it going|how is it going|comment allez-vous|comment vas-tu|ça va|ca va)\b/iu', $text) === 1) {
        return $fr
            ? 'Très bien, merci. Comment puis-je vous aider concernant Lambert Electromec ?'
            : 'I’m well, thank you. How can I help you with Lambert Electromec today?';
    }

    return $fr
        ? 'Bonjour. Je peux vous aider à consulter les services, divisions, projets publiés, Facility Management et coordonnées de Lambert Electromec.'
        : 'Hello. I can help you explore Lambert Electromec’s published services, divisions, projects, Facility Management and contact information.';
}

/**
 * Words of the visitor's own message that can carry Lambert meaning (the same
 * tokens retrieval uses, so stop words such as "you" or "how" are excluded).
 */
function aiScopeMeaningfulTokens(string $message, string $locale): array
{
    return array_values(array_filter(
        aiKnowledgeSearchTokens($message, $locale),
        static fn (string $token): bool => preg_match('/\p{L}/u', $token) === 1
    ));
}

/**
 * Relevance check for questions with no company or service word: more than
 * half of the meaningful words must appear (as whole words) in ONE retrieved
 * Lambert source. The source's kind (project, division...) counts as part of
 * it, so "Projects in Abuja" passes on a project located in Abuja, and
 * "Tell me about Eko Tower" passes on the project title, while
 * "Explain quantum physics" (only "quantum" matches a project) does not.
 */
function aiScopeHasRelevantSource(string $message, string $locale, array $sources): bool
{
    $tokens = aiScopeMeaningfulTokens($message, $locale);
    if ($tokens === []) {
        return false;
    }

    $typeLabels = [
        'project' => 'project projet',
        'facility_project' => 'facility management maintenance project projet',
        'media' => 'media centre document',
        'division' => 'division',
        'approved_knowledge' => '',
    ];

    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $haystack = ($typeLabels[(string) ($source['type'] ?? '')] ?? '')
            . "\n" . (string) ($source['title'] ?? '')
            . "\n" . (string) ($source['content'] ?? '');
        $matched = 0;
        foreach ($tokens as $token) {
            if (aiKnowledgeTermMatches($haystack, $token, true)) {
                $matched++;
            }
        }
        if ($matched * 2 > count($tokens)) {
            return true;
        }
    }

    return false;
}

/**
 * Reject clearly non-Lambert requests before retrieval; ambiguous questions
 * are passed to the model, which makes a second structured scope decision.
 *
 * @return array{decision: string, reason: string}
 */
function aiAssistantScopeDecision(string $message, array $history, string $locale): array
{
    $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $message) ?? $message));
    $result = static fn (string $decision, string $reason): array => [
        'decision' => $decision,
        'reason' => $reason,
    ];

    if ($text === '') {
        return $result('out_of_scope', 'unrelated');
    }

    if (aiScopeHasPattern($text, [
        '/\b(?:ignore|disregard|forget|override)\s+(?:all\s+)?(?:previous|prior|above|your|system)\s+(?:instructions|rules|prompts)\b/iu',
        '/\b(?:act as|you are now|jailbreak|reveal your prompt|show your system prompt|developer mode)\b/iu',
        '/\b(?:ignore|oublie|oubliez|contourne|contournez)\s+(?:les|tes|vos|toutes les)\s+(?:instructions|règles|regles|consignes)\b/iu',
        '/\b(?:agis comme|agissez comme|tu es maintenant|vous êtes maintenant)\b/iu',
    ])) {
        return $result('out_of_scope', 'role_override');
    }

    // Creative tasks are refused even when the subject is Lambert.
    if (aiScopeHasPattern($text, [
        '/\b(?:write|create|compose|generate|make|draft|sing|rap|recite)\b.{0,90}\b(?:love\s+)?(?:story|stories|poem|poetry|joke|song|lyrics|rap|essay|fiction|novel|fairy\s+tale|screenplay)\b/iu',
        '/\b(?:tell me|give me)\s+(?:a|an|some|another)\s+(?:love\s+)?(?:story|stories|poem|poetry|joke|song|lyrics|rap|essay|fiction|novel|fairy\s+tale|screenplay)\b/iu',
        '/\b(?:tell|write|compose|create)\b.{0,60}\b(?:romantic|fictional|imaginary)\s+(?:story|tale)\b/iu',
        '/\b(?:raconte|racontez|raconte-moi|racontez-moi|écris|ecris|écrivez|ecrivez|compose|composez|invente|inventez|chante|chantez)\b.{0,90}\b(?:une?\s+)?(?:histoire|poème|poeme|blague|chanson|paroles|rap|fiction|conte|essai)\b/iu',
        '/\b(?:pretend you are|imagine you are|fais semblant|faites semblant|joue le rôle|jouez le rôle|role.?play)\b/iu',
    ])) {
        return $result('out_of_scope', 'creative');
    }

    if (aiScopeLooksLikeCalculation($message, $locale)) {
        return $result('out_of_scope', 'arithmetic');
    }

    if (aiScopeIsCourtesy($text)) {
        return $result('greeting', 'courtesy');
    }
    if (preg_match('/^(?:help|help me|help me please|can you help me|how can you help(?: me)?|what can you do|what do you do|what can i ask(?: you)?|aide|aidez-moi|pouvez-vous m[\x27’]aider|comment pouvez-vous (?:m[\x27’])?aider|que pouvez-vous faire)[.!?\s]*$/iu', $text) === 1) {
        return $result('greeting', 'help');
    }

    $strongAnchor = aiScopeHasLambertAnchor($text, true);

    // Weather, independent public events, current time and general facts aren't
    // Lambert enquiries merely because they contain Lagos or "you".
    if (aiScopeHasPattern($text, [
        '/^(?:how|why)\s+(?:does|do|did)\s+(?:a|an|the)\s+[\p{L}\s-]{2,40}\s+work[\s?.!]*$/iu',
        '/\b(?:what year is it|what day is it|what month is it|what\x27s the date|what is the date)\b/iu',
        '/\b(?:restaurants?|bars?|nightclubs?|things to do|places to visit|tourist|tourism|flights?|airlines?|visa|hotel booking|book a hotel|where to eat|where to stay|world war|how many years ago|how long ago|football|soccer|basketball|tennis|premier league|champions league|fifa|nba|movies?|films?|series|tv shows?|celebrit(?:y|ies)|bitcoin|crypto(?:currency)?|lottery|horoscope|dating|girlfriend|boyfriend)\b/iu',
        '/\b(?:who invented|who discovered|who wrote|who painted|prime minister|governor of|king of|queen of|richest|in the world|in africa|weather|forecast|temperature|rainfall|rain today|world cup|football score|sports? scores?|match result|president of|capital of|photosynthesis|current date|today\x27s date|today\x{2019}s date|what time is it|what\x27s the time|what\x{2019}s the time|latest news|stock price|trivia)\b/iu',
        '/\b(?:qui a inventé|premier ministre|au monde|président du|présidente du|président des|météo|meteo|température|temperature|coupe du monde|président de|president de|capitale de|photosynthèse|photosynthese|quelle heure est-il|date d\x27aujourd\x27hui|actualités générales|actualites generales)\b/iu',
    ]) && !$strongAnchor) {
        return $result('out_of_scope', 'general_knowledge');
    }

    // Textbook definitions of everyday words ("What is engineering?") are
    // general knowledge even though the word appears on Lambert's website.
    if (!$strongAnchor && preg_match('/^(?:what is|what\x27s|what\x{2019}s|what are|define|explain|qu\x27est-ce que|c\x27est quoi|définis|explique)\s+(?:an?\s+|the\s+|l[ae\x27]\s*|une?\s+)?(?:engineering|construction|electricity|energy|power|physics|chemistry|science|technology|a project|project management|project manager|ingénierie|électricité|énergie|physique|chimie|technologie)(?:\s+to\s+a\s+child)?[\s?.!]*$/iu', $text) === 1) {
        return $result('out_of_scope', 'general_knowledge');
    }

    if (!$strongAnchor && aiScopeHasPattern($text, [
        '/\b(?:write|draft|compose|prepare|create)\b.{0,40}\b(?:cover letter|cv|resume|résumé|email|e-mail|essay|speech|letter|report|article|blog post|assignment)\b/iu',
        '/\b(?:rédige|rédigez|rediger|rédiger|écris|ecris|écrivez|ecrivez|prépare|préparez)\b.{0,40}\b(?:lettre de motivation|cv|e-mail|email|courriel|dissertation|discours|lettre|rapport|article)\b/iu',
        '/\b(?:homework|maths homework|math homework|assignment|python code|javascript code|write code|programming|debug my|translate|translation of|recipe|how to cook|medical advice|health advice|legal advice|financial advice|investment advice|diagnose my|symptoms|medication|lawsuit|stock tips)\b/iu',
        '/\b(?:devoirs|devoir|exercice scolaire|programmation|code python|traduire|traduis|traduisez|traduction de|recette|cuisiner|conseil médical|conseils médicaux|conseil juridique|conseil financier)\b/iu',
    ])) {
        return $result('out_of_scope', 'unrelated_task');
    }

    // An explicit follow-up inherits scope only from the most recent visitor turn.
    if (aiScopeHasPattern($text, [
        '/^(?:tell me more|more details|can you elaborate|what about(?: that| this)?|and (?:in|for|about) [\p{L}\p{N} ]+|continue|go on|please elaborate)[.!?\s]*$/iu',
        '/^(?:dis-m\x27en plus|dites-m\x27en plus|explique davantage|pouvez-vous préciser|et (?:au|en|sur|pour) [\p{L}\p{N} ]+|continuez)[.!?\s]*$/iu',
    ])) {
        for ($i = count($history) - 1; $i >= 0; --$i) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            $previous = trim((string) ($history[$i]['content'] ?? ''));
            if ($previous === '') {
                break;
            }
            $previousDecision = aiAssistantScopeDecision($previous, array_slice($history, 0, $i), $locale);
            return ($previousDecision['decision'] ?? '') === 'out_of_scope'
                ? $result('out_of_scope', 'unrelated_followup')
                : $result('in_scope', 'lambert_followup');
        }
        return $result('out_of_scope', 'unrelated_followup');
    }

    // No company or service word: let it through only if Lambert's published
    // content actually covers it. createLambertWebsiteAssistantResponse() runs
    // that relevance check after retrieval for the 'uncertain' reason (so
    // project names such as "Eko Tower" still work, while "Explain quantum
    // physics" is refused). The model then makes a second scope decision.
    return $result('in_scope', $strongAnchor ? 'lambert_topic' : 'uncertain');
}
