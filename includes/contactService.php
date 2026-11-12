<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contentService.php';
require_once __DIR__ . '/runtimeSettingsService.php';
require_once __DIR__ . '/uploadService.php';

function sanitizeContactPlainText(
    mixed $value,
    int $maxLength,
    bool $required,
    string $field,
    bool $preserveLineBreaks = false,
    ?string $responseField = null
): string {
    if (!is_string($value) && !is_numeric($value)) {
        if ($required) {
            jsonResponse(['status' => 'Failed', 'message' => $field . ' is required.', 'field' => $responseField], 422);
        }
        return '';
    }

    $text = (string) $value;
    $text = str_replace("\0", '', $text);
    $text = strip_tags($text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    if ($preserveLineBreaks) {
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[\t ]+/u', ' ', $line)),
            explode("\n", $text)
        );
        $text = trim((string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
    } else {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    if ($required && $text === '') {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' is required.', 'field' => $responseField], 422);
    }

    if (mb_strlen($text) > $maxLength) {
        jsonResponse([
            'status' => 'Failed',
            'message' => $field . ' must not exceed ' . $maxLength . ' characters.',
            'field' => $responseField,
        ], 422);
    }

    return $text;
}

function validateContactEmail(mixed $value): string
{
    $email = strtolower(sanitizeContactPlainText($value, 254, true, 'Email', false, 'email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Enter a valid email address.', 'field' => 'email'], 422);
    }

    return $email;
}

function validateContactPhone(mixed $value): string
{
    $phone = sanitizeContactPlainText($value, 80, true, 'Phone number', false, 'phone');
    if (!preg_match('/^[0-9+().\-\s]{7,80}$/', $phone)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Enter a valid phone number.', 'field' => 'phone'], 422);
    }

    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        jsonResponse(['status' => 'Failed', 'message' => 'Enter a valid phone number.', 'field' => 'phone'], 422);
    }

    return $phone;
}

function validateContactLocale(mixed $value): string
{
    $locale = strtolower(sanitizeContactPlainText($value, 5, false, 'Locale'));
    return $locale === 'fr' ? 'fr' : 'en';
}

function validateContactPageUrl(mixed $value): ?string
{
    $url = sanitizeContactPlainText($value, 1000, false, 'Page URL');
    if ($url === '') {
        return null;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid form page URL.'], 422);
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Invalid form page URL.'], 422);
    }

    return $url;
}

function contactSubjectByKey(mysqli $conn, string $subjectKey, bool $activeOnly = true): ?array
{
    $subjectKey = trim(strtolower($subjectKey));
    if ($subjectKey === '' || mb_strlen($subjectKey) > 100) {
        return null;
    }

    $sql = 'SELECT id, subject_key, label_en, label_fr, recipient_emails, cc_emails, status, display_order
            FROM contact_subjects
            WHERE subject_key = ?';
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $subjectKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function validateContactSubject(mysqli $conn, mixed $value): array
{
    $subjectKey = strtolower(sanitizeContactPlainText($value, 100, true, 'Subject', false, 'subject'));
    $subject = contactSubjectByKey($conn, $subjectKey, true);
    if (!$subject) {
        jsonResponse(['status' => 'Failed', 'message' => 'Select a valid subject.', 'field' => 'subject'], 422);
    }

    return $subject;
}

function publicContactSubjects(mysqli $conn, string $lang): array
{
    $labelColumn = $lang === 'fr' ? "COALESCE(NULLIF(label_fr, ''), label_en)" : 'label_en';
    $result = $conn->query(
        "SELECT subject_key, {$labelColumn} AS label, display_order
         FROM contact_subjects
         WHERE status = 'active'
         ORDER BY display_order ASC, id ASC"
    );

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'value' => (string) $row['subject_key'],
            'label' => (string) $row['label'],
            'display_order' => (int) $row['display_order'],
        ];
    }
    return $items;
}

function contactPositionOptions(): array
{
    return [
        'account-officer' => 'Account Officer',
        'admin-asst-front-desk' => 'Administrative Assistant / Front Desk',
        'quantity-surveyor' => 'Quantity Surveyor',
        'other-positions' => 'Other Positions',
    ];
}

function contactLocationOptions(): array
{
    return [
        'nigeria' => 'Nigeria',
        'cote-divoire' => "Cote D' Ivoire",
        'ghana' => 'Ghana',
        'other' => 'Others',
    ];
}

function validateContactChoice(mixed $value, array $allowed, string $field, string $responseField): string
{
    $choice = strtolower(sanitizeContactPlainText($value, 160, true, $field, false, $responseField));
    if (!array_key_exists($choice, $allowed)) {
        jsonResponse(['status' => 'Failed', 'message' => 'Select a valid ' . strtolower($field) . '.', 'field' => $responseField], 422);
    }
    return $choice;
}

function validateJobApplicationFields(array $input, string $subjectKey): array
{
    if ($subjectKey !== 'job-vacancy') {
        return [
            'position' => null,
            'other_position' => null,
            'location' => null,
            'other_location' => null,
        ];
    }

    $position = validateContactChoice(
        $input['position'] ?? null,
        contactPositionOptions(),
        'Position',
        'position'
    );
    $location = validateContactChoice(
        $input['location'] ?? null,
        contactLocationOptions(),
        'Location',
        'location'
    );

    $otherPosition = null;
    if ($position === 'other-positions') {
        $otherPosition = sanitizeContactPlainText(
            $input['other_position'] ?? null,
            190,
            true,
            'Other position',
            false,
            'other_position'
        );
    }

    $otherLocation = null;
    if ($location === 'other') {
        $otherLocation = sanitizeContactPlainText(
            $input['other_location'] ?? null,
            190,
            true,
            'Other location',
            false,
            'other_location'
        );
    }

    return [
        'position' => $position,
        'other_position' => $otherPosition,
        'location' => $location,
        'other_location' => $otherLocation,
    ];
}

function validateTermsAccepted(mixed $value): bool
{
    $accepted = booleanFlag($value, false);
    if (!$accepted) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'You must accept the Terms & Conditions.',
            'field' => 'terms_accepted',
        ], 422);
    }
    return true;
}

function validateFormElapsedTime(mixed $value): int
{
    $elapsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($elapsed === false) {
        jsonResponse(['status' => 'Failed', 'message' => 'We could not verify this submission.'], 422);
    }

    $minimum = max(0, min(30000, (int) envValue('CONTACT_MIN_FORM_ELAPSED_MS', 2500)));
    if ((int) $elapsed < $minimum) {
        jsonResponse(['status' => 'Failed', 'message' => 'We could not verify this submission.'], 422);
    }

    return (int) $elapsed;
}

function contactIpHash(): string
{
    return hash_hmac('sha256', clientIpAddress(), applicationHashSecret('CONTACT_IP_HASH_SECRET'));
}

function contactSubmissionFingerprint(
    string $subjectKey,
    string $email,
    string $message,
    ?string $position,
    ?string $location
): string {
    $value = implode('|', [
        strtolower($subjectKey),
        strtolower($email),
        trim($message),
        strtolower((string) $position),
        strtolower((string) $location),
    ]);

    return hash_hmac('sha256', $value, applicationHashSecret('CONTACT_FINGERPRINT_SECRET'));
}

function contactRateLimitValue(string $key, int $default, int $minimum, int $maximum): int
{
    return max($minimum, min($maximum, (int) runtimeSettingValue($key, $default)));
}

function assertContactSubmissionAllowed(
    mysqli $conn,
    string $email,
    string $ipHash,
    string $fingerprint
): ?array {
    $ipWindowMinutes = contactRateLimitValue('CONTACT_RATE_LIMIT_WINDOW_MINUTES', 15, 1, 1440);
    $ipMax = contactRateLimitValue('CONTACT_RATE_LIMIT_MAX', 5, 1, 100);
    $emailWindowMinutes = contactRateLimitValue('CONTACT_EMAIL_RATE_WINDOW_MINUTES', 60, 1, 1440);
    $emailMax = contactRateLimitValue('CONTACT_EMAIL_RATE_MAX', 8, 1, 100);
    $duplicateWindowMinutes = contactRateLimitValue('CONTACT_DUPLICATE_WINDOW_MINUTES', 10, 1, 1440);

    $now = new DateTimeImmutable('now', appTimeZone());
    $ipCutoff = $now->modify('-' . $ipWindowMinutes . ' minutes')->format('Y-m-d H:i:s');
    $emailCutoff = $now->modify('-' . $emailWindowMinutes . ' minutes')->format('Y-m-d H:i:s');
    $duplicateCutoff = $now->modify('-' . $duplicateWindowMinutes . ' minutes')->format('Y-m-d H:i:s');

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM contact_submissions WHERE ip_hash = ? AND created_at >= ?');
    $stmt->bind_param('ss', $ipHash, $ipCutoff);
    $stmt->execute();
    $ipCount = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    if ($ipCount >= $ipMax) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Too many enquiries were submitted recently. Please try again later.',
        ], 429);
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM contact_submissions WHERE email = ? AND created_at >= ?');
    $stmt->bind_param('ss', $email, $emailCutoff);
    $stmt->execute();
    $emailCount = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    if ($emailCount >= $emailMax) {
        jsonResponse([
            'status' => 'Failed',
            'message' => 'Too many enquiries were submitted recently. Please try again later.',
        ], 429);
    }

    $stmt = $conn->prepare(
        'SELECT id FROM contact_submissions WHERE submission_fingerprint = ? AND created_at >= ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->bind_param('ss', $fingerprint, $duplicateCutoff);
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $duplicate ?: null;
}

function normalizeContactRecipientEmails(mixed $value, string $field = 'Recipient emails'): array
{
    if ($value === null || $value === '') {
        return [];
    }

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $value = $decoded;
        } else {
            $value = preg_split('/[,;\n]+/', $value) ?: [];
        }
    }

    if (!is_array($value)) {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' must be a list of email addresses.'], 422);
    }

    $emails = [];
    foreach ($value as $candidate) {
        if (!is_string($candidate) && !is_numeric($candidate)) {
            jsonResponse(['status' => 'Failed', 'message' => $field . ' contains an invalid email address.'], 422);
        }
        $email = strtolower(trim((string) $candidate));
        if ($email === '') {
            continue;
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['status' => 'Failed', 'message' => $field . ' contains an invalid email address.'], 422);
        }
        $emails[$email] = $email;
    }

    $emails = array_values($emails);
    if (count($emails) > 20) {
        jsonResponse(['status' => 'Failed', 'message' => $field . ' can contain at most 20 addresses.'], 422);
    }

    return $emails;
}

function decodeContactRecipientEmails(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    $candidates = is_array($decoded)
        ? $decoded
        : (preg_split('/[,;\n]+/', $value) ?: []);

    $emails = [];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) && !is_numeric($candidate)) {
            continue;
        }
        $email = strtolower(trim((string) $candidate));
        if ($email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = $email;
        }
    }

    return array_slice(array_values($emails), 0, 20);
}

function encodeContactRecipientEmails(array $emails): string
{
    return json_encode(array_values($emails), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function formatContactRoutingRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'subject_key' => (string) $row['subject_key'],
        'label_en' => (string) $row['label_en'],
        'label_fr' => (string) $row['label_fr'],
        'recipient_emails' => decodeContactRecipientEmails($row['recipient_emails'] ?? null),
        'cc_emails' => decodeContactRecipientEmails($row['cc_emails'] ?? null),
        'status' => (string) $row['status'],
        'display_order' => (int) $row['display_order'],
    ];
}

function contactAttachmentBySubmission(mysqli $conn, int $submissionId): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, submission_id, attachment_type, file_path, original_filename, mime_type, file_size, sha256, created_at
         FROM contact_attachments
         WHERE submission_id = ? AND attachment_type = 'cv'
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function contactAttachmentById(mysqli $conn, int $attachmentId): ?array
{
    $stmt = $conn->prepare(
        "SELECT ca.id, ca.submission_id, ca.attachment_type, ca.file_path, ca.original_filename,
                ca.mime_type, ca.file_size, ca.sha256, ca.created_at,
                cs.full_name, cs.email, cs.subject_key
         FROM contact_attachments ca
         INNER JOIN contact_submissions cs ON cs.id = ca.submission_id
         WHERE ca.id = ? AND ca.attachment_type = 'cv'
         LIMIT 1"
    );
    $stmt->bind_param('i', $attachmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function contactDeliveryHistory(mysqli $conn, int $submissionId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare(
        "SELECT ced.id, ced.submission_id, ced.recipient_emails, ced.cc_emails, ced.status, ced.provider,
                ced.error_message, ced.attempted_at, ced.sent_at, ced.attempted_by,
                au.first_name AS admin_first_name, au.last_name AS admin_last_name, au.email AS admin_email
         FROM contact_email_deliveries ced
         LEFT JOIN admin_users au ON au.id = ced.attempted_by
         WHERE ced.submission_id = ?
         ORDER BY ced.id DESC
         LIMIT ?"
    );
    $stmt->bind_param('ii', $submissionId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $attemptedBy = null;
        if (!empty($row['attempted_by'])) {
            $attemptedBy = [
                'id' => (int) $row['attempted_by'],
                'first_name' => (string) ($row['admin_first_name'] ?? ''),
                'last_name' => (string) ($row['admin_last_name'] ?? ''),
                'email' => (string) ($row['admin_email'] ?? ''),
            ];
        }
        $items[] = [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'provider' => isset($row['provider']) && $row['provider'] !== null ? (string) $row['provider'] : null,
            'recipient_emails' => decodeContactRecipientEmails($row['recipient_emails'] ?? null),
            'cc_emails' => decodeContactRecipientEmails($row['cc_emails'] ?? null),
            'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'attempted_at' => (string) $row['attempted_at'],
            'sent_at' => $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            'attempted_by' => $attemptedBy,
        ];
    }
    $stmt->close();
    return $items;
}

function ensureContactSubmissionExists(mysqli $conn, int $submissionId): array
{
    $stmt = $conn->prepare(
        'SELECT cs.*, csub.label_en AS subject_label_en, csub.label_fr AS subject_label_fr,
                au.first_name AS handler_first_name, au.last_name AS handler_last_name, au.email AS handler_email
         FROM contact_submissions cs
         INNER JOIN contact_subjects csub ON csub.subject_key = cs.subject_key
         LEFT JOIN admin_users au ON au.id = cs.handled_by
         WHERE cs.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        jsonResponse(['status' => 'Failed', 'message' => 'Contact submission not found.'], 404);
    }

    return $row;
}

function formatContactSubmission(array $row, ?array $attachment = null, ?array $deliveries = null): array
{
    $handler = null;
    if (!empty($row['handled_by'])) {
        $handler = [
            'id' => (int) $row['handled_by'],
            'first_name' => (string) ($row['handler_first_name'] ?? ''),
            'last_name' => (string) ($row['handler_last_name'] ?? ''),
            'email' => (string) ($row['handler_email'] ?? ''),
        ];
    }

    $cv = null;
    if ($attachment) {
        $cv = [
            'id' => (int) $attachment['id'],
            'original_filename' => (string) $attachment['original_filename'],
            'mime_type' => (string) $attachment['mime_type'],
            'file_size' => (int) $attachment['file_size'],
            'sha256' => (string) ($attachment['sha256'] ?? ''),
            'created_at' => (string) $attachment['created_at'],
        ];
    }

    $payload = [
        'id' => (int) $row['id'],
        'subject' => [
            'key' => (string) $row['subject_key'],
            'label_en' => (string) ($row['subject_label_en'] ?? ''),
            'label_fr' => (string) ($row['subject_label_fr'] ?? ''),
        ],
        'locale' => (string) ($row['locale'] ?? 'en'),
        'full_name' => (string) $row['full_name'],
        'email' => (string) $row['email'],
        'phone' => (string) ($row['phone'] ?? ''),
        'message' => (string) $row['message'],
        'position' => $row['position'] !== null ? (string) $row['position'] : null,
        'other_position' => $row['other_position'] !== null ? (string) $row['other_position'] : null,
        'location' => $row['location'] !== null ? (string) $row['location'] : null,
        'other_location' => $row['other_location'] !== null ? (string) $row['other_location'] : null,
        'page_url' => $row['page_url'] !== null ? (string) $row['page_url'] : null,
        'terms_accepted' => (bool) $row['terms_accepted'],
        'terms_accepted_at' => $row['terms_accepted_at'] !== null ? (string) $row['terms_accepted_at'] : null,
        'turnstile_verified' => (bool) $row['turnstile_verified'],
        'status' => (string) $row['status'],
        'email_delivery' => [
            'status' => (string) ($row['email_delivery_status'] ?? 'pending'),
            'last_attempt_at' => $row['email_last_attempt_at'] !== null ? (string) $row['email_last_attempt_at'] : null,
            'sent_at' => $row['email_sent_at'] !== null ? (string) $row['email_sent_at'] : null,
            'error' => $row['email_delivery_error'] !== null ? (string) $row['email_delivery_error'] : null,
        ],
        'handled_by' => $handler,
        'handled_at' => $row['handled_at'] !== null ? (string) $row['handled_at'] : null,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'cv' => $cv,
    ];

    if ($deliveries !== null) {
        $payload['email_delivery']['history'] = $deliveries;
    }

    return $payload;
}

function insertContactSubmission(
    mysqli $conn,
    array $subject,
    array $input,
    array $jobFields,
    string $email,
    string $phone,
    string $message,
    string $locale,
    ?string $pageUrl,
    string $ipHash,
    string $fingerprint,
    bool $turnstileVerified
): int {
    $subjectKey = (string) $subject['subject_key'];
    $fullName = sanitizeContactPlainText($input['full_name'] ?? null, 120, true, 'Full name', false, 'full_name');
    if (mb_strlen($fullName) < 2) {
        jsonResponse(['status' => 'Failed', 'message' => 'Enter your full name.', 'field' => 'full_name'], 422);
    }

    $position = $jobFields['position'];
    $otherPosition = $jobFields['other_position'];
    $location = $jobFields['location'];
    $otherLocation = $jobFields['other_location'];
    $termsAcceptedAt = (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s');
    $userAgent = requestUserAgent();
    $turnstileFlag = $turnstileVerified ? 1 : 0;

    $stmt = $conn->prepare(
        "INSERT INTO contact_submissions
            (subject_key, locale, full_name, email, phone, message, position, other_position,
             location, other_location, page_url, terms_accepted, terms_accepted_at, status,
             ip_hash, submission_fingerprint, user_agent, turnstile_verified, email_delivery_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'new', ?, ?, ?, ?, 'pending')"
    );
    executePrepared($stmt, 'sssssssssssssssi', [
        $subjectKey,
        $locale,
        $fullName,
        $email,
        $phone,
        $message,
        $position,
        $otherPosition,
        $location,
        $otherLocation,
        $pageUrl,
        $termsAcceptedAt,
        $ipHash,
        $fingerprint,
        $userAgent,
        $turnstileFlag,
    ]);
    $submissionId = (int) $conn->insert_id;
    $stmt->close();
    return $submissionId;
}

function insertContactAttachment(mysqli $conn, int $submissionId, array $stored): int
{
    $type = 'cv';
    $filePath = (string) $stored['file_path'];
    $original = (string) $stored['original_name'];
    $mime = (string) $stored['mime'];
    $size = (int) $stored['size'];
    $sha256 = (string) $stored['sha256'];

    $stmt = $conn->prepare(
        'INSERT INTO contact_attachments
            (submission_id, attachment_type, file_path, original_filename, mime_type, file_size, sha256)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssis', $submissionId, $type, $filePath, $original, $mime, $size, $sha256);
    $stmt->execute();
    $attachmentId = (int) $conn->insert_id;
    $stmt->close();
    return $attachmentId;
}

function updateContactEmailState(
    mysqli $conn,
    int $submissionId,
    string $status,
    ?string $errorMessage,
    ?string $sentAt
): void {
    $attemptedAt = (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s');
    $errorMessage = $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null;

    $stmt = $conn->prepare(
        'UPDATE contact_submissions
         SET email_delivery_status = ?, email_delivery_error = ?, email_last_attempt_at = ?,
             email_sent_at = COALESCE(?, email_sent_at)
         WHERE id = ?'
    );
    $stmt->bind_param('ssssi', $status, $errorMessage, $attemptedAt, $sentAt, $submissionId);
    $stmt->execute();
    $stmt->close();
}

function recordContactEmailDelivery(
    mysqli $conn,
    int $submissionId,
    array $recipients,
    array $cc,
    string $status,
    ?string $errorMessage = null,
    ?int $attemptedBy = null,
    ?string $sentAt = null,
    ?string $provider = null
): void {
    $recipientJson = encodeContactRecipientEmails($recipients);
    $provider = in_array($provider, ['graph', 'smtp'], true) ? $provider : null;
    $ccJson = encodeContactRecipientEmails($cc);
    $errorMessage = $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null;

    $stmt = $conn->prepare(
        'INSERT INTO contact_email_deliveries
            (submission_id, attempted_by, recipient_emails, cc_emails, status, provider, error_message, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissssss', $submissionId, $attemptedBy, $recipientJson, $ccJson, $status, $provider, $errorMessage, $sentAt);
    $stmt->execute();
    $stmt->close();

    updateContactEmailState($conn, $submissionId, $status, $errorMessage, $sentAt);
}
