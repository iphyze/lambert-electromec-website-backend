<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contentService.php';
require_once __DIR__ . '/contactService.php';
require_once __DIR__ . '/runtimeSettingsService.php';
require_once __DIR__ . '/graphMailService.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function smtpConfigured(): bool
{
    return trim((string) envValue('SMTP_HOST', '')) !== ''
        && trim((string) envValue('SMTP_FROM_EMAIL', '')) !== '';
}

/** Provider selected in System Settings (default: Microsoft 365 Graph). */
function mailTransportSelected(): string
{
    $value = strtolower(trim((string) runtimeSettingValue('MAIL_TRANSPORT', 'graph')));
    return in_array($value, ['graph', 'smtp'], true) ? $value : 'graph';
}

function mailTransportLabel(string $transport): string
{
    return $transport === 'smtp' ? 'SMTP' : 'Microsoft 365 (Graph)';
}

function mailTransportConfigured(?string $transport = null): bool
{
    $transport ??= mailTransportSelected();
    return $transport === 'smtp' ? smtpConfigured() : graphMailConfigured();
}

function mailFallbackToSmtpEnabled(): bool
{
    return runtimeSettingBoolean('MAIL_FALLBACK_TO_SMTP', false);
}

/**
 * Providers to try, in order. Microsoft 365 falls back to SMTP only when the
 * admin enabled it and SMTP is configured.
 *
 * @return list<string>
 */
function mailTransportPlan(): array
{
    $selected = mailTransportSelected();
    $plan = [];
    if (mailTransportConfigured($selected)) {
        $plan[] = $selected;
    }
    if ($selected === 'graph' && mailFallbackToSmtpEnabled() && smtpConfigured()) {
        $plan[] = 'smtp';
    }

    return array_values(array_unique($plan));
}

function mailTransportNotConfiguredMessage(): string
{
    return mailTransportLabel(mailTransportSelected()) . ' is not configured.';
}

/** Send a transport-neutral message through SMTP (PHPMailer). */
function lambertSendViaSmtp(array $message): void
{
    $mail = configureLambertMailer();
    foreach ((array) ($message['to'] ?? []) as $recipient) {
        $mail->addAddress((string) $recipient['email'], (string) ($recipient['name'] ?? ''));
    }
    foreach ((array) ($message['cc'] ?? []) as $recipient) {
        $mail->addCC((string) $recipient['email'], (string) ($recipient['name'] ?? ''));
    }
    foreach ((array) ($message['reply_to'] ?? []) as $recipient) {
        $mail->addReplyTo((string) $recipient['email'], (string) ($recipient['name'] ?? ''));
    }

    $mail->Subject = (string) ($message['subject'] ?? '');
    foreach ((array) ($message['inline_images'] ?? []) as $image) {
        $mail->addEmbeddedImage(
            (string) $image['path'],
            (string) $image['cid'],
            (string) ($image['name'] ?? basename((string) $image['path'])),
            'base64',
            (string) ($image['mime'] ?? 'image/png')
        );
    }
    foreach ((array) ($message['attachments'] ?? []) as $file) {
        $mail->addAttachment((string) $file['path'], (string) ($file['name'] ?? basename((string) $file['path'])));
    }

    if (trim((string) ($message['html'] ?? '')) !== '') {
        $mail->isHTML(true);
        $mail->Body = (string) $message['html'];
        $mail->AltBody = (string) ($message['text'] ?? '');
    } else {
        $mail->Body = (string) ($message['text'] ?? '');
    }

    $mail->send();
}

/**
 * Send through the provider selected in System Settings.
 *
 * @return array{provider: string, fallback_used: bool}
 * @throws RuntimeException when no provider is configured or every attempt fails
 */
function lambertSendEmail(array $message): array
{
    $plan = mailTransportPlan();
    if ($plan === []) {
        throw new RuntimeException(mailTransportNotConfiguredMessage());
    }

    $errors = [];
    foreach ($plan as $index => $transport) {
        try {
            if ($transport === 'smtp') {
                lambertSendViaSmtp($message);
            } else {
                graphMailSend($message);
            }
            return ['provider' => $transport, 'fallback_used' => $index > 0];
        } catch (Throwable $error) {
            $safe = $error instanceof GraphMailException || $error instanceof MailException
                ? $error->getMessage()
                : 'Email delivery failed.';
            error_log('Lambert email (' . $transport . ') error: ' . $error->getMessage());
            $errors[] = mailTransportLabel($transport) . ': ' . $safe;
        }
    }

    throw new RuntimeException(mb_substr(implode(' | ', $errors), 0, 500));
}

function mailMimeType(string $path, string $fallback = 'application/octet-stream'): string
{
    $mime = function_exists('mime_content_type') ? @mime_content_type($path) : false;
    return is_string($mime) && $mime !== '' ? $mime : $fallback;
}

function configureLambertMailer(): PHPMailer
{
    if (!smtpConfigured()) {
        throw new RuntimeException('Email delivery is not configured.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = trim((string) envValue('SMTP_HOST', ''));
    $mail->Port = max(1, min(65535, (int) envValue('SMTP_PORT', 587)));
    $mail->Timeout = max(5, min(30, (int) envValue('SMTP_TIMEOUT_SECONDS', 12)));
    $mail->CharSet = 'UTF-8';
    $mail->SMTPAuth = envBoolean('SMTP_AUTH', true);

    if ($mail->SMTPAuth) {
        $mail->Username = trim((string) envValue('SMTP_USERNAME', ''));
        $mail->Password = (string) envValue('SMTP_PASSWORD', '');
    }

    $encryption = strtolower(trim((string) envValue('SMTP_ENCRYPTION', 'tls')));
    if ($encryption === 'ssl' || $encryption === 'smtps') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls' || $encryption === 'starttls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    }

    $fromEmail = strtolower(trim((string) envValue('SMTP_FROM_EMAIL', '')));
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP_FROM_EMAIL is invalid.');
    }
    $fromName = trim((string) envValue('SMTP_FROM_NAME', 'Lambert Electromec'));
    $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : 'Lambert Electromec');

    return $mail;
}

function contactSubjectDisplayLabel(array $submission): string
{
    $locale = (string) ($submission['locale'] ?? 'en');
    if ($locale === 'fr') {
        $fr = trim((string) ($submission['subject_label_fr'] ?? ''));
        if ($fr !== '') {
            return $fr;
        }
    }
    return trim((string) ($submission['subject_label_en'] ?? $submission['subject_key'] ?? 'Website enquiry'));
}

function contactChoiceLabel(?string $value, array $options, ?string $other = null): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if ($other !== null && trim($other) !== '') {
        return trim($other);
    }
    return $options[$value] ?? $value;
}

function contactEmailTextBody(array $submission): string
{
    $subjectLabel = contactSubjectDisplayLabel($submission);
    $lines = [
        'New Lambert Electromec website submission',
        '',
        'Reference: #' . (int) $submission['id'],
        'Subject: ' . $subjectLabel,
        'Name: ' . (string) $submission['full_name'],
        'Email: ' . (string) $submission['email'],
        'Phone: ' . (string) ($submission['phone'] ?? ''),
    ];

    if ((string) ($submission['subject_key'] ?? '') === 'job-vacancy') {
        $position = contactChoiceLabel(
            $submission['position'] ?? null,
            contactPositionOptions(),
            $submission['other_position'] ?? null
        );
        $location = contactChoiceLabel(
            $submission['location'] ?? null,
            contactLocationOptions(),
            $submission['other_location'] ?? null
        );
        $lines[] = 'Position: ' . (string) $position;
        $lines[] = 'Location: ' . (string) $location;
    }

    $lines[] = 'Language: ' . strtoupper((string) ($submission['locale'] ?? 'en'));
    if (!empty($submission['page_url'])) {
        $lines[] = 'Submitted from: ' . (string) $submission['page_url'];
    }
    $lines[] = 'Submitted at: ' . (string) $submission['created_at'];
    $lines[] = '';
    $lines[] = 'Message:';
    $lines[] = (string) $submission['message'];

    return implode("\n", $lines);
}

function contactEmailLogoPath(): ?string
{
    $path = dirname(__DIR__) . '/assets/email/lambert-logo.png';
    return is_file($path) ? $path : null;
}

function contactEmailFriendlyDate(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }

    try {
        $date = new DateTimeImmutable($raw, appTimeZone());
        return $date->setTimezone(appTimeZone())->format('d M Y · H:i T');
    } catch (Throwable) {
        return $raw;
    }
}

function contactEmailHtmlBody(array $submission, bool $logoEmbedded = true): string
{
    $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subjectLabel = contactSubjectDisplayLabel($submission);
    $fullName = trim((string) ($submission['full_name'] ?? ''));
    $email = strtolower(trim((string) ($submission['email'] ?? '')));
    $phone = trim((string) ($submission['phone'] ?? ''));
    $locale = strtoupper((string) ($submission['locale'] ?? 'en'));
    $reference = '#' . (int) ($submission['id'] ?? 0);
    $submittedAt = contactEmailFriendlyDate($submission['created_at'] ?? '');
    $pageUrl = trim((string) ($submission['page_url'] ?? ''));
    $isJobVacancy = (string) ($submission['subject_key'] ?? '') === 'job-vacancy';

    $rows = [
        ['Reference', $reference],
        ['Enquiry type', $subjectLabel],
        ['Name', $fullName],
        ['Email', $email],
        ['Phone', $phone !== '' ? $phone : '—'],
    ];

    if ($isJobVacancy) {
        $rows[] = [
            'Position',
            (string) contactChoiceLabel(
                $submission['position'] ?? null,
                contactPositionOptions(),
                $submission['other_position'] ?? null
            ),
        ];
        $rows[] = [
            'Location',
            (string) contactChoiceLabel(
                $submission['location'] ?? null,
                contactLocationOptions(),
                $submission['other_location'] ?? null
            ),
        ];
    }

    $rows[] = ['Language', $locale];
    $rows[] = ['Received', $submittedAt];

    $details = '';
    foreach ($rows as [$label, $value]) {
        $details .= '<tr>'
            . '<td style="width:148px;padding:13px 18px 13px 0;border-bottom:1px solid #e8eee9;color:#718078;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;vertical-align:top;">' . $e($label) . '</td>'
            . '<td style="padding:13px 0;border-bottom:1px solid #e8eee9;color:#17231b;font-size:14px;font-weight:600;line-height:1.55;vertical-align:top;word-break:break-word;">' . $e($value !== '' ? $value : '—') . '</td>'
            . '</tr>';
    }

    $replyButton = '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $replyLabel = $fullName !== '' ? 'Reply to ' . $fullName : 'Reply to sender';
        $replyButton = '<a href="mailto:' . $e($email) . '" style="display:inline-block;padding:12px 20px;border-radius:10px;background:#48A750;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;line-height:20px;">' . $e($replyLabel) . '</a>';
    }

    $sourceBlock = '';
    if ($pageUrl !== '') {
        $sourceBlock = '<tr><td style="padding-top:18px;color:#7a877f;font-size:12px;line-height:1.6;">Submitted from: <span style="color:#44524a;word-break:break-all;">' . $e($pageUrl) . '</span></td></tr>';
    }

    $logo = $logoEmbedded
        ? '<img src="cid:lambert-contact-logo" width="178" alt="Lambert Electromec" style="display:block;width:178px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">'
        : '<div style="color:#2f7d38;font-size:24px;font-weight:800;letter-spacing:-.03em;">Lambert <span style="color:#54615a;font-size:13px;font-weight:600;letter-spacing:0;">electromec</span></div>';

    $preheader = 'New Lambert Electromec website enquiry from ' . ($fullName !== '' ? $fullName : 'a website visitor') . '.';
    $message = nl2br($e((string) ($submission['message'] ?? '')));

    return '<!doctype html>'
        . '<html><body style="margin:0;padding:0;background:#f3f6f3;font-family:Arial,Helvetica,sans-serif;color:#17231b;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . $e($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f6f3;margin:0;padding:0;">'
        . '<tr><td align="center" style="padding:30px 14px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:660px;background:#ffffff;border:1px solid #dfe8e1;border-radius:18px;overflow:hidden;box-shadow:0 14px 40px rgba(23,35,27,.08);">'
        . '<tr><td style="height:6px;background:#48A750;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:26px 32px 20px;background:#ffffff;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
        . '<td valign="middle">' . $logo . '</td>'
        . '<td align="right" valign="middle"><span style="display:inline-block;padding:7px 11px;border-radius:999px;background:#edf7ee;color:#2f7d38;font-size:10px;font-weight:800;letter-spacing:.11em;text-transform:uppercase;">Website enquiry</span></td>'
        . '</tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:0 32px 28px;background:#ffffff;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#103c2b;border-radius:16px;overflow:hidden;">'
        . '<tr><td style="padding:26px 28px 28px;">'
        . '<div style="margin:0 0 8px;color:#9bd4a0;font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;">New contact form submission</div>'
        . '<h1 style="margin:0;color:#ffffff;font-size:27px;line-height:1.18;font-weight:800;letter-spacing:-.025em;">' . $e($subjectLabel !== '' ? $subjectLabel : 'Website enquiry') . '</h1>'
        . '<p style="margin:12px 0 0;color:#d9e8de;font-size:14px;line-height:1.65;">A new message has been received through the Lambert Electromec website.</p>'
        . '</td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:0 32px 4px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
        . '<td style="padding:0 0 14px;color:#2f7d38;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">Contact details</td>'
        . '<td align="right" style="padding:0 0 14px;color:#829087;font-size:12px;font-weight:700;">' . $e($reference) . '</td>'
        . '</tr></table>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-top:1px solid #e8eee9;">' . $details . '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:26px 32px 0;">'
        . '<div style="margin:0 0 10px;color:#2f7d38;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">Message</div>'
        . '<div style="padding:20px 22px;border:1px solid #dce9de;border-radius:14px;background:#f6fbf6;color:#25342b;font-size:15px;line-height:1.7;word-break:break-word;">' . $message . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 32px 30px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td>' . $replyButton . '</td></tr>'
        . $sourceBlock
        . '</table>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 32px;background:#f7faf7;border-top:1px solid #e5ece6;">'
        . '<p style="margin:0;color:#718078;font-size:11px;line-height:1.65;">This is an automated notification from the Lambert Electromec website. Replying to this email will address the sender directly.</p>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

function resolveManagedCvAbsolutePath(?array $attachment): ?string
{
    if (!$attachment || empty($attachment['file_path'])) {
        return null;
    }

    $root = realpath(cvUploadRoot());
    $target = realpath(dirname(__DIR__) . '/' . ltrim((string) $attachment['file_path'], '/'));
    if ($root === false || $target === false || !is_file($target)) {
        return null;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $targetNormalized = str_replace('\\', '/', $target);
    if (!str_starts_with($targetNormalized, $rootPrefix)) {
        return null;
    }

    return $target;
}

function deliverContactSubmissionEmail(mysqli $conn, int $submissionId, ?int $attemptedBy = null): array
{
    $submission = ensureContactSubmissionExists($conn, $submissionId);
    $subject = contactSubjectByKey($conn, (string) $submission['subject_key'], false);
    if (!$subject) {
        recordContactEmailDelivery($conn, $submissionId, [], [], 'not_configured', 'Contact subject routing was not found.', $attemptedBy);
        return ['status' => 'not_configured'];
    }

    $recipients = decodeContactRecipientEmails($subject['recipient_emails'] ?? null);
    $cc = decodeContactRecipientEmails($subject['cc_emails'] ?? null);
    $cc = array_values(array_diff($cc, $recipients));

    if ($recipients === []) {
        recordContactEmailDelivery($conn, $submissionId, [], $cc, 'not_configured', 'No recipient email is configured for this subject.', $attemptedBy);
        return ['status' => 'not_configured'];
    }

    if (mailTransportPlan() === []) {
        recordContactEmailDelivery($conn, $submissionId, $recipients, $cc, 'not_configured', mailTransportNotConfiguredMessage(), $attemptedBy, null, mailTransportSelected());
        return ['status' => 'not_configured'];
    }

    $message = [
        'to' => array_map(static fn (string $email): array => ['email' => $email], $recipients),
        'cc' => array_map(static fn (string $email): array => ['email' => $email], $cc),
        'reply_to' => [],
        'subject' => '[Lambert Website] ' . contactSubjectDisplayLabel($submission) . ' — ' . (string) $submission['full_name'],
        'inline_images' => [],
        'attachments' => [],
    ];

    $replyTo = strtolower(trim((string) $submission['email']));
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $message['reply_to'][] = ['email' => $replyTo, 'name' => (string) $submission['full_name']];
    }

    $logoPath = contactEmailLogoPath();
    if ($logoPath !== null) {
        $message['inline_images'][] = [
            'path' => $logoPath,
            'cid' => 'lambert-contact-logo',
            'name' => 'lambert-logo.png',
            'mime' => 'image/png',
        ];
    }
    $message['html'] = contactEmailHtmlBody($submission, $logoPath !== null);
    $message['text'] = contactEmailTextBody($submission);

    $attachment = contactAttachmentBySubmission($conn, $submissionId);
    if ($attachment && runtimeSettingBoolean('CONTACT_EMAIL_ATTACH_CV', true)) {
        $absolutePath = resolveManagedCvAbsolutePath($attachment);
        if ($absolutePath !== null) {
            $message['attachments'][] = [
                'path' => $absolutePath,
                'name' => (string) $attachment['original_filename'],
                'mime' => (string) ($attachment['mime_type'] ?? '') !== ''
                    ? (string) $attachment['mime_type']
                    : mailMimeType($absolutePath),
            ];
        }
    }

    try {
        $result = lambertSendEmail($message);
        $sentAt = (new DateTimeImmutable('now', appTimeZone()))->format('Y-m-d H:i:s');
        recordContactEmailDelivery($conn, $submissionId, $recipients, $cc, 'sent', null, $attemptedBy, $sentAt, $result['provider']);
        return ['status' => 'sent', 'sent_at' => $sentAt, 'provider' => $result['provider']];
    } catch (Throwable $error) {
        error_log('Lambert contact email error for submission #' . $submissionId . ': ' . $error->getMessage());
        $safeMessage = $error instanceof RuntimeException ? $error->getMessage() : 'Email delivery failed.';
        recordContactEmailDelivery($conn, $submissionId, $recipients, $cc, 'failed', $safeMessage, $attemptedBy, null, mailTransportSelected());
        return ['status' => 'failed'];
    }
}

/** Short branded message used by the System Settings "Send test email" action. */
function lambertTestEmailMessage(string $recipient, string $recipientName = ''): array
{
    $provider = mailTransportLabel(mailTransportSelected());
    $sentAt = (new DateTimeImmutable('now', appTimeZone()))->format('d M Y · H:i T');
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logoPath = contactEmailLogoPath();

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1f2933;max-width:560px;margin:0 auto;padding:24px;">'
        . ($logoPath !== null ? '<img src="cid:lambert-contact-logo" width="160" alt="Lambert Electromec" style="display:block;width:160px;height:auto;border:0;margin-bottom:20px;">' : '')
        . '<h2 style="margin:0 0 12px;font-size:18px;color:#2f8a3a;">Email delivery test</h2>'
        . '<p style="margin:0 0 10px;font-size:14px;line-height:1.6;">This test message confirms that the Lambert website can send notification emails.</p>'
        . '<p style="margin:0 0 4px;font-size:13px;"><strong>Provider:</strong> ' . $e($provider) . '</p>'
        . '<p style="margin:0;font-size:13px;"><strong>Sent:</strong> ' . $e($sentAt) . '</p>'
        . '<p style="margin:18px 0 0;font-size:12px;color:#6b7280;">Sent from Lambert Administration → System Settings.</p>'
        . '</div>';

    $message = [
        'to' => [['email' => $recipient, 'name' => $recipientName]],
        'cc' => [],
        'reply_to' => [],
        'subject' => '[Lambert Website] Email delivery test — ' . $provider,
        'html' => $html,
        'text' => "Email delivery test\n\nThis test message confirms that the Lambert website can send notification emails.\nProvider: {$provider}\nSent: {$sentAt}\n",
        'inline_images' => [],
        'attachments' => [],
    ];
    if ($logoPath !== null) {
        $message['inline_images'][] = ['path' => $logoPath, 'cid' => 'lambert-contact-logo', 'name' => 'lambert-logo.png', 'mime' => 'image/png'];
    }

    return $message;
}
