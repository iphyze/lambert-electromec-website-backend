<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

/**
 * Microsoft 365 (Graph API) mail transport.
 *
 * Uses the OAuth 2.0 client-credentials flow and the Graph `sendMail` endpoint,
 * with no SDK. Credentials are read from the server .env only:
 *
 *   GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET, GRAPH_SENDER,
 *   GRAPH_SECRET_EXPIRES (informational), GRAPH_TIMEOUT_SECONDS (optional),
 *   GRAPH_SAVE_TO_SENT_ITEMS (optional, default false)
 *
 * Azure prerequisites: an app registration with the Mail.Send *application*
 * permission and admin consent, ideally restricted to the sender mailbox with
 * an Exchange Online Application Access Policy.
 *
 * Messages use the transport-neutral shape built in emailService.php:
 *   to, cc, reply_to: list of ['email' => string, 'name' => ?string]
 *   subject, html, text
 *   inline_images: list of ['path', 'cid', 'name', 'mime']
 *   attachments:   list of ['path', 'name', 'mime']
 *
 * Secrets and access tokens are never logged, returned or stored.
 */
final class GraphMailException extends RuntimeException
{
}

// Graph rejects sendMail requests above ~4 MB. Base64 adds a third, so keep the
// raw attachment total for the simple path comfortably below that.
const GRAPH_MAIL_SIMPLE_ATTACHMENT_LIMIT = 2_500_000;
// Attachments of 3 MB or more must be uploaded through an upload session.
const GRAPH_MAIL_LARGE_ATTACHMENT_BYTES = 3_000_000;
// Upload-session chunks must be multiples of 320 KiB.
const GRAPH_MAIL_UPLOAD_CHUNK_BYTES = 327_680 * 10;

/**
 * Test hook: outside production, GRAPH_TEST_BASE_URL can point both the token
 * and API calls at a local mock server (used by scripts/testMailTransport.php).
 * It is ignored in production.
 */
function graphMailTestBaseUrl(): ?string
{
    if (appEnvironment() === 'production') {
        return null;
    }
    $base = rtrim(trim((string) envValue('GRAPH_TEST_BASE_URL', '')), '/');
    return $base !== '' ? $base : null;
}

function graphMailApiBase(): string
{
    $test = graphMailTestBaseUrl();
    return $test !== null ? $test . '/v1.0' : 'https://graph.microsoft.com/v1.0';
}

function graphMailLoginBase(): string
{
    return graphMailTestBaseUrl() ?? 'https://login.microsoftonline.com';
}

function graphMailConfig(): array
{
    return [
        'tenant_id' => trim((string) envValue('GRAPH_TENANT_ID', '')),
        'client_id' => trim((string) envValue('GRAPH_CLIENT_ID', '')),
        'client_secret' => (string) envValue('GRAPH_CLIENT_SECRET', ''),
        'sender' => strtolower(trim((string) envValue('GRAPH_SENDER', ''))),
        'timeout' => max(5, min(60, (int) envValue('GRAPH_TIMEOUT_SECONDS', 20))),
        'save_to_sent_items' => envBoolean('GRAPH_SAVE_TO_SENT_ITEMS', false),
    ];
}

function graphMailConfigured(): bool
{
    $config = graphMailConfig();

    return $config['tenant_id'] !== ''
        && $config['client_id'] !== ''
        && trim($config['client_secret']) !== ''
        && filter_var($config['sender'], FILTER_VALIDATE_EMAIL) !== false;
}

/** Obtain (and cache for this request) an app-only access token. */
function graphMailAccessToken(bool $forceRefresh = false): string
{
    static $cache = ['token' => null, 'expires_at' => 0];

    if (!$forceRefresh && is_string($cache['token']) && $cache['expires_at'] > time() + 60) {
        return $cache['token'];
    }

    if (!graphMailConfigured()) {
        throw new GraphMailException('Microsoft 365 (Graph) is not configured.');
    }

    $config = graphMailConfig();
    if (preg_match('/^[A-Za-z0-9.\-]{1,100}$/', $config['tenant_id']) !== 1) {
        throw new GraphMailException('GRAPH_TENANT_ID is invalid.');
    }

    $response = graphMailHttp(
        'POST',
        graphMailLoginBase() . '/' . rawurlencode($config['tenant_id']) . '/oauth2/v2.0/token',
        http_build_query([
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC3986),
        ['Content-Type: application/x-www-form-urlencoded']
    );

    $payload = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($payload) || !is_string($payload['access_token'] ?? null)) {
        $code = is_array($payload) ? (string) ($payload['error'] ?? '') : '';
        throw new GraphMailException(graphMailTokenErrorMessage($code, $response['status']));
    }

    $cache = [
        'token' => $payload['access_token'],
        'expires_at' => time() + max(60, (int) ($payload['expires_in'] ?? 3600)),
    ];

    return $cache['token'];
}

function graphMailTokenErrorMessage(string $code, int $status): string
{
    return match ($code) {
        'invalid_client' => 'Microsoft 365 rejected the client credentials (check GRAPH_CLIENT_ID / GRAPH_CLIENT_SECRET, or the secret may have expired).',
        'unauthorized_client' => 'The Azure app is not authorised for this tenant.',
        'invalid_request' => 'Microsoft 365 rejected the token request (check GRAPH_TENANT_ID).',
        default => 'Could not obtain a Microsoft 365 access token (HTTP ' . $status . ').',
    };
}

/**
 * Low-level HTTP call with timeouts and limited retries for 429/503/504 and
 * transient network errors (honouring Retry-After, capped at 10 seconds).
 *
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function graphMailHttp(string $method, string $url, ?string $body = null, array $headers = [], int $maxRetries = 2): array
{
    if (!function_exists('curl_init')) {
        throw new GraphMailException('The PHP curl extension is required for Microsoft 365 email.');
    }

    $config = graphMailConfig();
    $attempt = 0;

    while (true) {
        $attempt++;
        $responseHeaders = [];
        $curl = curl_init($url);
        if ($curl === false) {
            throw new GraphMailException('Unable to initialise the Microsoft 365 connection.');
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $config['timeout']),
            CURLOPT_TIMEOUT => $config['timeout'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($curl);
        $curlError = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $transient = $curlError !== 0 || in_array($status, [429, 503, 504], true);
        if ($transient && $attempt <= $maxRetries) {
            $wait = (int) ($responseHeaders['retry-after'] ?? 0);
            sleep(max(1, min(10, $wait > 0 ? $wait : $attempt * 2)));
            continue;
        }

        if ($curlError !== 0) {
            throw new GraphMailException($curlError === CURLE_OPERATION_TIMEDOUT
                ? 'Microsoft 365 did not respond in time.'
                : 'Could not connect to Microsoft 365.');
        }

        return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'headers' => $responseHeaders];
    }
}

/** Authenticated Graph API call (refreshes the token once on 401). */
function graphMailApi(string $method, string $path, ?array $json = null): array
{
    $send = static function (bool $refresh) use ($method, $path, $json): array {
        $headers = [
            'Authorization: Bearer ' . graphMailAccessToken($refresh),
            'Accept: application/json',
        ];
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return graphMailHttp($method, graphMailApiBase() . $path, $body, $headers);
    };

    $response = $send(false);
    if ($response['status'] === 401) {
        $response = $send(true);
    }

    return $response;
}

/** Short, safe description of a Graph error (never includes tokens or secrets). */
function graphMailApiErrorMessage(array $response, string $action): string
{
    $payload = json_decode($response['body'], true);
    $code = is_array($payload) ? (string) ($payload['error']['code'] ?? '') : '';
    $message = is_array($payload) ? (string) ($payload['error']['message'] ?? '') : '';

    $friendly = match ($code) {
        'ErrorAccessDenied', 'Authorization_RequestDenied', 'AccessDenied' =>
            'Microsoft 365 denied access. Check the Mail.Send application permission, admin consent and the Application Access Policy for the sender mailbox.',
        'ErrorInvalidUser', 'MailboxNotEnabledForRESTAPI', 'ResourceNotFound', 'ErrorItemNotFound' =>
            'The Microsoft 365 sender mailbox (GRAPH_SENDER) was not found or is not enabled.',
        'ErrorMessageSizeExceeded' => 'The email is larger than Microsoft 365 allows.',
        'ErrorInvalidRecipients' => 'Microsoft 365 rejected one or more recipient addresses.',
        default => '',
    };

    if ($friendly === '') {
        $detail = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        $friendly = 'Microsoft 365 could not ' . $action . ' (HTTP ' . $response['status'] . ($code !== '' ? ', ' . $code : '') . ')'
            . ($detail !== '' ? ': ' . mb_substr($detail, 0, 220) : '.');
    }

    return mb_substr($friendly, 0, 450);
}

function graphMailRecipients(array $list): array
{
    $recipients = [];
    foreach ($list as $item) {
        $email = strtolower(trim((string) ($item['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $address = ['address' => $email];
        $name = trim((string) ($item['name'] ?? ''));
        if ($name !== '') {
            $address['name'] = $name;
        }
        $recipients[] = ['emailAddress' => $address];
    }

    return $recipients;
}

function graphMailFileAttachment(string $path, string $name, string $mime, ?string $contentId = null): array
{
    $content = @file_get_contents($path);
    if ($content === false) {
        throw new GraphMailException('An email attachment could not be read.');
    }

    $attachment = [
        '@odata.type' => '#microsoft.graph.fileAttachment',
        'name' => $name,
        'contentType' => $mime !== '' ? $mime : 'application/octet-stream',
        'contentBytes' => base64_encode($content),
    ];
    if ($contentId !== null) {
        $attachment['isInline'] = true;
        $attachment['contentId'] = $contentId;
    }

    return $attachment;
}

/** Build the Graph message resource (without large attachments). */
function graphMailMessageResource(array $message, array $smallAttachments): array
{
    $resource = [
        'subject' => (string) ($message['subject'] ?? ''),
        'body' => [
            'contentType' => trim((string) ($message['html'] ?? '')) !== '' ? 'HTML' : 'Text',
            'content' => trim((string) ($message['html'] ?? '')) !== ''
                ? (string) $message['html']
                : (string) ($message['text'] ?? ''),
        ],
        'toRecipients' => graphMailRecipients((array) ($message['to'] ?? [])),
    ];

    $cc = graphMailRecipients((array) ($message['cc'] ?? []));
    if ($cc !== []) {
        $resource['ccRecipients'] = $cc;
    }
    $replyTo = graphMailRecipients((array) ($message['reply_to'] ?? []));
    if ($replyTo !== []) {
        $resource['replyTo'] = $replyTo;
    }

    $attachments = [];
    foreach ((array) ($message['inline_images'] ?? []) as $image) {
        $attachments[] = graphMailFileAttachment(
            (string) $image['path'],
            (string) ($image['name'] ?? basename((string) $image['path'])),
            (string) ($image['mime'] ?? 'image/png'),
            (string) $image['cid']
        );
    }
    foreach ($smallAttachments as $file) {
        $attachments[] = graphMailFileAttachment(
            (string) $file['path'],
            (string) ($file['name'] ?? basename((string) $file['path'])),
            (string) ($file['mime'] ?? 'application/octet-stream')
        );
    }
    if ($attachments !== []) {
        $resource['attachments'] = $attachments;
    }

    return $resource;
}

/** Send a message through Microsoft 365. Throws GraphMailException on failure. */
function graphMailSend(array $message): void
{
    if (!graphMailConfigured()) {
        throw new GraphMailException('Microsoft 365 (Graph) is not configured.');
    }
    if (graphMailRecipients((array) ($message['to'] ?? [])) === []) {
        throw new GraphMailException('No valid recipient address was provided.');
    }

    $config = graphMailConfig();
    $mailbox = '/users/' . rawurlencode($config['sender']);

    $small = [];
    $large = [];
    $total = 0;
    foreach ((array) ($message['inline_images'] ?? []) as $image) {
        $total += (int) @filesize((string) $image['path']);
    }
    foreach ((array) ($message['attachments'] ?? []) as $file) {
        $size = (int) @filesize((string) $file['path']);
        if ($size <= 0) {
            throw new GraphMailException('An email attachment could not be read.');
        }
        $file['size'] = $size;
        if ($size >= GRAPH_MAIL_LARGE_ATTACHMENT_BYTES) {
            $large[] = $file;
        } else {
            $small[] = $file;
            $total += $size;
        }
    }

    // Simple path: one sendMail call when everything fits in a single request.
    if ($large === [] && $total <= GRAPH_MAIL_SIMPLE_ATTACHMENT_LIMIT) {
        $response = graphMailApi('POST', $mailbox . '/sendMail', [
            'message' => graphMailMessageResource($message, $small),
            'saveToSentItems' => $config['save_to_sent_items'],
        ]);
        if ($response['status'] !== 202) {
            throw new GraphMailException(graphMailApiErrorMessage($response, 'send the email'));
        }
        return;
    }

    // Large path (e.g. a CV of several MB): create a draft, upload big files
    // through upload sessions, then send the draft.
    $moveToLarge = [];
    $keptSmall = [];
    $running = 0;
    foreach ($small as $file) {
        if ($running + $file['size'] > GRAPH_MAIL_SIMPLE_ATTACHMENT_LIMIT) {
            $moveToLarge[] = $file;
        } else {
            $keptSmall[] = $file;
            $running += $file['size'];
        }
    }
    $large = array_merge($large, $moveToLarge);

    $draft = graphMailApi('POST', $mailbox . '/messages', graphMailMessageResource($message, $keptSmall));
    $draftPayload = json_decode($draft['body'], true);
    $draftId = is_array($draftPayload) ? (string) ($draftPayload['id'] ?? '') : '';
    if ($draft['status'] !== 201 || $draftId === '') {
        throw new GraphMailException(graphMailApiErrorMessage($draft, 'prepare the email'));
    }
    $messagePath = $mailbox . '/messages/' . rawurlencode($draftId);

    try {
        foreach ($large as $file) {
            graphMailUploadLargeAttachment($messagePath, $file);
        }

        $sent = graphMailApi('POST', $messagePath . '/send');
        if ($sent['status'] !== 202) {
            throw new GraphMailException(graphMailApiErrorMessage($sent, 'send the email'));
        }
    } catch (Throwable $error) {
        // Best effort: do not leave an unsent draft in the notification mailbox.
        try {
            graphMailApi('DELETE', $messagePath);
        } catch (Throwable) {
        }
        throw $error instanceof GraphMailException ? $error : new GraphMailException('Microsoft 365 could not send the email.');
    }
}

function graphMailUploadLargeAttachment(string $messagePath, array $file): void
{
    $path = (string) $file['path'];
    $size = (int) $file['size'];
    $session = graphMailApi('POST', $messagePath . '/attachments/createUploadSession', [
        'AttachmentItem' => [
            'attachmentType' => 'file',
            'name' => (string) ($file['name'] ?? basename($path)),
            'size' => $size,
            'contentType' => (string) ($file['mime'] ?? 'application/octet-stream'),
        ],
    ]);
    $sessionPayload = json_decode($session['body'], true);
    $uploadUrl = is_array($sessionPayload) ? (string) ($sessionPayload['uploadUrl'] ?? '') : '';
    $validUploadUrl = str_starts_with($uploadUrl, 'https://')
        || (graphMailTestBaseUrl() !== null && str_starts_with($uploadUrl, (string) graphMailTestBaseUrl()));
    if ($session['status'] !== 201 || !$validUploadUrl) {
        throw new GraphMailException(graphMailApiErrorMessage($session, 'upload the attachment'));
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new GraphMailException('An email attachment could not be read.');
    }

    try {
        $offset = 0;
        while ($offset < $size) {
            $chunk = fread($handle, GRAPH_MAIL_UPLOAD_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                throw new GraphMailException('An email attachment could not be read.');
            }
            $end = $offset + strlen($chunk) - 1;
            // The pre-authenticated upload URL must be called WITHOUT the
            // Authorization header.
            $response = graphMailHttp('PUT', $uploadUrl, $chunk, [
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($chunk),
                'Content-Range: bytes ' . $offset . '-' . $end . '/' . $size,
            ]);
            if (!in_array($response['status'], [200, 201], true)) {
                throw new GraphMailException(graphMailApiErrorMessage($response, 'upload the attachment'));
            }
            $offset = $end + 1;
        }
    } finally {
        fclose($handle);
    }
}
