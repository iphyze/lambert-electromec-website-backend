<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/contactService.php';
require_once __DIR__ . '/../../includes/turnstileService.php';
require_once __DIR__ . '/../../includes/emailService.php';

requireMethod('POST');
requireTrustedRequestOrigin();
applyNoStoreHeaders();

$maxRequestBytes = cvUploadMaxBytes() + 2 * 1024 * 1024;
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxRequestBytes) {
    jsonResponse(['status' => 'Failed', 'message' => 'Request payload is too large.'], 413);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_contains($contentType, 'multipart/form-data')) {
    jsonResponse(['status' => 'Failed', 'message' => 'Contact submissions must use multipart form data.'], 415);
}

// Honeypot. Return the normal success shape without storing anything so bots do
// not receive a useful signal that their submission was rejected.
$honeypot = sanitizeContactPlainText($_POST['website'] ?? '', 255, false, 'Verification field');
if ($honeypot !== '') {
    jsonResponse(['success' => true, 'status' => 'Success'], 201);
}

validateFormElapsedTime($_POST['form_elapsed_ms'] ?? null);
$subject = validateContactSubject($conn, $_POST['subject'] ?? null);
$subjectKey = (string) $subject['subject_key'];
$fullName = sanitizeContactPlainText($_POST['full_name'] ?? null, 120, true, 'Full name', false, 'full_name');
if (mb_strlen($fullName) < 2) {
    jsonResponse(['status' => 'Failed', 'message' => 'Enter your full name.', 'field' => 'full_name'], 422);
}
$email = validateContactEmail($_POST['email'] ?? null);
$phone = validateContactPhone($_POST['phone'] ?? null);
$message = sanitizeContactPlainText($_POST['message'] ?? null, 5000, true, 'Message', true, 'message');
if (mb_strlen($message) < 10) {
    jsonResponse(['status' => 'Failed', 'message' => 'Message must contain at least 10 characters.', 'field' => 'message'], 422);
}
$locale = validateContactLocale($_POST['locale'] ?? 'en');
$pageUrl = validateContactPageUrl($_POST['page_url'] ?? null);
validateTermsAccepted($_POST['terms_accepted'] ?? null);
$jobFields = validateJobApplicationFields($_POST, $subjectKey);

$hasCv = isset($_FILES['cv']) && (int) ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($subjectKey === 'job-vacancy' && !$hasCv) {
    jsonResponse(['status' => 'Failed', 'message' => 'Please upload your CV.', 'field' => 'cv'], 422);
}
if ($subjectKey !== 'job-vacancy' && $hasCv) {
    jsonResponse(['status' => 'Failed', 'message' => 'A CV can only be uploaded for a job vacancy submission.', 'field' => 'cv'], 422);
}
if ($hasCv && is_array($_FILES['cv']['name'] ?? null)) {
    jsonResponse(['status' => 'Failed', 'message' => 'Upload one CV only.', 'field' => 'cv'], 422);
}
if ($hasCv) {
    cvUploadSpecification($_FILES['cv']);
}

$ipHash = contactIpHash();
$fingerprint = contactSubmissionFingerprint(
    $subjectKey,
    $email,
    $message,
    $jobFields['position'],
    $jobFields['location']
);
$duplicate = assertContactSubmissionAllowed($conn, $email, $ipHash, $fingerprint);
if ($duplicate) {
    jsonResponse([
        'success' => true,
        'status' => 'Success',
        'duplicate' => true,
        'reference' => (int) $duplicate['id'],
    ], 200);
}

$turnstileToken = sanitizeContactPlainText($_POST['turnstile_token'] ?? '', 4096, false, 'Human verification token');
$turnstileVerified = verifyTurnstileToken($turnstileToken);
$storedCv = null;
$submissionId = 0;

$conn->begin_transaction();
try {
    $submissionId = insertContactSubmission(
        $conn,
        $subject,
        $_POST,
        $jobFields,
        $email,
        $phone,
        $message,
        $locale,
        $pageUrl,
        $ipHash,
        $fingerprint,
        $turnstileVerified
    );

    if ($hasCv) {
        $storedCv = storeCvUpload($_FILES['cv']);
        insertContactAttachment($conn, $submissionId, $storedCv);
    }

    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    if (is_array($storedCv) && !empty($storedCv['file_path'])) {
        safelyDeleteCvUpload((string) $storedCv['file_path']);
    }
    throw $error;
}

// The submission is already safely stored. Email delivery is deliberately
// best-effort so a temporary email-provider failure never causes a genuine enquiry or CV
// to be lost or reported to the visitor as unsubmitted.
try {
    deliverContactSubmissionEmail($conn, $submissionId, null);
} catch (Throwable $error) {
    error_log('Lambert contact delivery workflow error for submission #' . $submissionId . ': ' . $error->getMessage());
}

jsonResponse([
    'success' => true,
    'status' => 'Success',
    'reference' => $submissionId,
], 201);
