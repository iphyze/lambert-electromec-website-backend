<?php

declare(strict_types=1);

require_once __DIR__ . '/aiConfig.php';
require_once __DIR__ . '/aiTextService.php';

function contactAiLocalNormalize(string $value): string
{
    return aiTextNormalizeInline($value);
}

function contactAiLocalContainsAny(string $text, array $needles): bool
{
    $text = mb_strtolower($text);
    foreach ($needles as $needle) {
        if (str_contains($text, mb_strtolower((string) $needle))) {
            return true;
        }
    }
    return false;
}

function contactAiLocalClassification(string $subjectKey): string
{
    return match ($subjectKey) {
        'job-vacancy' => 'job_application',
        'technical-commercial-request' => 'technical_commercial',
        'supplier-vendor' => 'supplier_vendor',
        'maintenance-inquiry' => 'maintenance',
        'customer-feedback' => 'customer_feedback',
        'general-enquiry' => 'general_enquiry',
        default => 'other',
    };
}

function contactAiLocalPriority(string $subjectKey, string $message): string
{
    $text = mb_strtolower(contactAiLocalNormalize($message));

    $urgent = [
        'urgent', 'emergency', 'immediately', 'critical failure', 'system down',
        'power outage', 'shutdown', 'breakdown', 'major leak', 'flooding',
        'fire incident', 'fire emergency', 'safety incident', 'life safety',
    ];
    if (contactAiLocalContainsAny($text, $urgent)) {
        return 'urgent';
    }

    $high = [
        'tender', 'rfq', 'rfp', 'quotation', 'request for quote', 'request for proposal',
        'bid', 'proposal deadline', 'submission deadline', 'contract', 'purchase order',
        'site visit', 'mobilization', 'mobilisation', 'project requirement', 'project enquiry',
    ];
    if (contactAiLocalContainsAny($text, $high)) {
        return 'high';
    }

    if ($subjectKey === 'job-vacancy') {
        return 'low';
    }

    return 'normal';
}

function contactAiLocalCommercialRelevance(string $subjectKey, string $message, string $locale): array
{
    $text = mb_strtolower(contactAiLocalNormalize($message));
    $commercialSignals = [
        'tender', 'rfq', 'rfp', 'quotation', 'quote', 'proposal', 'contract', 'project',
        'maintenance', 'service', 'installation', 'commissioning', 'procurement', 'epc', 'mep',
    ];

    if (in_array($subjectKey, ['technical-commercial-request', 'maintenance-inquiry'], true)
        || contactAiLocalContainsAny($text, $commercialSignals)) {
        return [
            'level' => 'high',
            'reason' => $locale === 'fr'
                ? 'La demande semble liée à un projet potentiel, à un besoin technique ou à un service Lambert.'
                : 'The enquiry appears connected to a potential project, technical requirement or paid Lambert service.',
        ];
    }

    if ($subjectKey === 'supplier-vendor' || $subjectKey === 'general-enquiry') {
        return [
            'level' => 'medium',
            'reason' => $locale === 'fr'
                ? 'La demande peut soutenir une relation commerciale, mais ne présente pas encore de besoin de projet confirmé.'
                : 'The enquiry may support a business relationship but does not yet show a confirmed project requirement.',
        ];
    }

    return [
        'level' => 'low',
        'reason' => $locale === 'fr'
            ? 'La demande n’indique pas actuellement d’opportunité commerciale directe.'
            : 'The enquiry does not currently indicate a direct commercial opportunity.',
    ];
}

function contactAiLocalRelevantDivision(array $activeDivisions, string $message): string
{
    $text = mb_strtolower(contactAiLocalNormalize($message));
    $scores = [
        'dataCenter' => ['data centre', 'data center', 'datacenter', 'server', 'rack', 'hyperscale', 'mission critical', 'cloud'],
        'powerSolutions' => ['power', 'generator', 'transformer', 'solar', 'battery', 'renewable', 'substation', 'rm u', 'rmu', 'inverter', 'energy'],
        'oilGas' => ['oil', 'gas', 'refinery', 'petroleum', 'pipeline', 'hydrocarbon'],
        'buildingsFactories' => ['building', 'factory', 'hvac', 'plumbing', 'fire fighting', 'firefighting', 'mechanical', 'electrical', 'mep', 'commercial building', 'residential', 'hotel', 'hospital', 'hangar'],
    ];

    $bestKey = '';
    $bestScore = 0;
    foreach ($scores as $key => $keywords) {
        $score = 0;
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $score++;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestKey = $key;
        }
    }

    if ($bestKey === '' || $bestScore <= 0) {
        return '';
    }

    foreach ($activeDivisions as $division) {
        if (($division['key'] ?? '') === $bestKey) {
            return (string) ($division['name'] ?? '');
        }
    }

    return '';
}

function contactAiLocalRelevantService(string $subjectKey, string $message): string
{
    $text = mb_strtolower(contactAiLocalNormalize($message));

    if ($subjectKey === 'maintenance-inquiry' || contactAiLocalContainsAny($text, ['maintenance', 'repair', 'servicing', 'facility management', 'property management'])) {
        return 'Facility Management / LEM Maintenance';
    }
    if (contactAiLocalContainsAny($text, ['design', 'engineering', 'procurement', 'installation', 'commissioning', 'epc', 'epic'])) {
        return 'Engineering, Procurement, Installation and Commissioning';
    }
    if (contactAiLocalContainsAny($text, ['mep', 'mechanical', 'electrical', 'plumbing', 'piped services', 'hvac'])) {
        return 'MEP Services';
    }

    return '';
}

function contactAiLocalSummary(array $submission, string $locale): string
{
    $message = contactAiLocalNormalize((string) ($submission['message'] ?? ''));
    if (mb_strlen($message) > aiEnquirySummaryMaxChars() - 80) {
        $message = rtrim(mb_substr($message, 0, aiEnquirySummaryMaxChars() - 81)) . '…';
    }

    $subject = $locale === 'fr'
        ? (string) ($submission['subject_label_fr'] ?: $submission['subject_label_en'] ?? $submission['subject_key'] ?? '')
        : (string) ($submission['subject_label_en'] ?? $submission['subject_key'] ?? '');

    $prefix = $locale === 'fr' ? 'Demande reçue sous « ' : 'Enquiry submitted under “';
    $suffix = $locale === 'fr' ? ' » : ' : '”: ';
    return mb_substr($prefix . $subject . $suffix . $message, 0, aiEnquirySummaryMaxChars());
}

function contactAiLocalNextAction(string $classification, string $priority, string $relevantService, string $locale): string
{
    if ($locale === 'fr') {
        if ($priority === 'urgent') {
            return 'Faire examiner immédiatement la demande par le responsable concerné, confirmer l’accusé de réception et vérifier les informations techniques nécessaires avant toute réponse engageante.';
        }
        return match ($classification) {
            'job_application' => 'Transmettre la candidature au responsable du recrutement pour examen. Ne pas promettre de présélection ou d’entretien.',
            'technical_commercial' => 'Faire examiner la demande par l’équipe technique/commerciale et demander les informations manquantes avant de préparer une réponse ou une offre.',
            'supplier_vendor' => 'Vérifier la pertinence du fournisseur et transmettre la demande à l’équipe responsable des fournisseurs/achats si nécessaire.',
            'maintenance' => 'Transmettre la demande à LEM Maintenance pour évaluation du besoin, clarification de la portée et prochaine action.',
            'customer_feedback' => 'Examiner le retour, identifier le service concerné et préparer une réponse appropriée sans modifier le message original.',
            default => 'Examiner la demande, identifier le responsable approprié et répondre après vérification des informations nécessaires.',
        };
    }

    if ($priority === 'urgent') {
        return 'Escalate immediately to the responsible manager, acknowledge receipt, and verify the technical facts before making any commitment.';
    }

    return match ($classification) {
        'job_application' => 'Route the application to the recruitment owner for review. Do not promise shortlisting or an interview.',
        'technical_commercial' => 'Route to the technical/commercial team, review the requirement, and request any missing information before preparing a response or offer.',
        'supplier_vendor' => 'Review supplier relevance and route to the responsible vendor/procurement owner where appropriate.',
        'maintenance' => 'Route to LEM Maintenance to assess the requirement, clarify scope, and determine the appropriate next action.',
        'customer_feedback' => 'Review the feedback, identify the responsible service owner, and prepare an appropriate response while preserving the original message.',
        default => 'Review the enquiry, identify the appropriate owner, and respond after confirming any information required to proceed.',
    };
}

function contactAiLocalReply(string $classification, string $locale): string
{
    if ($locale === 'fr') {
        return match ($classification) {
            'job_application' => "Bonjour [Nom],\n\nMerci pour l’intérêt que vous portez à Lambert Electromec. Nous confirmons la réception de votre candidature. Elle sera examinée par l’équipe concernée et nous vous contacterons si des informations complémentaires ou une prochaine étape sont nécessaires.\n\nCordialement,\nLambert Electromec Limited",
            'technical_commercial' => "Bonjour [Nom],\n\nMerci d’avoir contacté Lambert Electromec concernant votre demande technique/commerciale. Nous avons bien reçu les informations transmises. Notre équipe les examinera et pourra vous contacter afin d’obtenir des précisions avant de vous indiquer la prochaine étape.\n\nCordialement,\nLambert Electromec Limited",
            'supplier_vendor' => "Bonjour [Nom],\n\nMerci pour votre intérêt à travailler avec Lambert Electromec en tant que fournisseur. Nous avons reçu votre demande et elle sera examinée par l’équipe concernée. Nous vous contacterons si des informations supplémentaires sont requises.\n\nCordialement,\nLambert Electromec Limited",
            'maintenance' => "Bonjour [Nom],\n\nMerci d’avoir contacté Lambert Electromec au sujet de votre besoin de maintenance. Nous avons reçu votre demande et l’équipe concernée l’examinera afin de déterminer les informations ou actions nécessaires pour la suite.\n\nCordialement,\nLambert Electromec Limited",
            'customer_feedback' => "Bonjour [Nom],\n\nMerci d’avoir partagé votre retour avec Lambert Electromec. Nous avons bien reçu votre message et il sera examiné par l’équipe concernée.\n\nCordialement,\nLambert Electromec Limited",
            default => "Bonjour [Nom],\n\nMerci d’avoir contacté Lambert Electromec. Nous avons bien reçu votre demande. Notre équipe l’examinera et vous contactera si des informations supplémentaires sont nécessaires.\n\nCordialement,\nLambert Electromec Limited",
        };
    }

    return match ($classification) {
        'job_application' => "Dear [Name],\n\nThank you for your interest in Lambert Electromec. We confirm receipt of your application. It will be reviewed by the appropriate team, and we will contact you if further information or a next step is required.\n\nKind regards,\nLambert Electromec Limited",
        'technical_commercial' => "Dear [Name],\n\nThank you for contacting Lambert Electromec regarding your technical/commercial requirement. We have received the information provided. Our team will review it and may contact you for further clarification before advising on the next step.\n\nKind regards,\nLambert Electromec Limited",
        'supplier_vendor' => "Dear [Name],\n\nThank you for your interest in working with Lambert Electromec as a supplier/vendor. We have received your enquiry and it will be reviewed by the appropriate team. We will contact you if additional information is required.\n\nKind regards,\nLambert Electromec Limited",
        'maintenance' => "Dear [Name],\n\nThank you for contacting Lambert Electromec regarding your maintenance requirement. We have received your enquiry and the appropriate team will review it to determine any information or action required for the next step.\n\nKind regards,\nLambert Electromec Limited",
        'customer_feedback' => "Dear [Name],\n\nThank you for sharing your feedback with Lambert Electromec. We have received your message and it will be reviewed by the appropriate team.\n\nKind regards,\nLambert Electromec Limited",
        default => "Dear [Name],\n\nThank you for contacting Lambert Electromec. We have received your enquiry. Our team will review it and contact you if any additional information is required.\n\nKind regards,\nLambert Electromec Limited",
    };
}

function createLambertLocalEnquiryAnalysis(array $submission, array $activeDivisions): array
{
    $locale = strtolower((string) ($submission['locale'] ?? 'en')) === 'fr' ? 'fr' : 'en';
    $subjectKey = (string) ($submission['subject_key'] ?? 'general-enquiry');
    $message = (string) ($submission['message'] ?? '');
    $classification = contactAiLocalClassification($subjectKey);
    $priority = contactAiLocalPriority($subjectKey, $message);
    $commercial = contactAiLocalCommercialRelevance($subjectKey, $message, $locale);
    $division = contactAiLocalRelevantDivision($activeDivisions, $message);
    $service = contactAiLocalRelevantService($subjectKey, $message);

    return [
        'analysis' => [
            'summary' => contactAiLocalSummary($submission, $locale),
            'classification' => $classification,
            'priority' => $priority,
            'commercial_relevance' => (string) $commercial['level'],
            'commercial_reason' => (string) $commercial['reason'],
            'relevant_division' => $division,
            'relevant_service' => $service,
            'recommended_next_action' => contactAiLocalNextAction($classification, $priority, $service, $locale),
            'suggested_reply' => mb_substr(contactAiLocalReply($classification, $locale), 0, aiEnquirySuggestedReplyMaxChars()),
        ],
        'provider' => [
            'provider' => 'local',
            'model' => 'lambert-enquiry-fallback-v1',
            'response_id' => null,
            'usage' => [],
            'fallback' => true,
        ],
    ];
}
