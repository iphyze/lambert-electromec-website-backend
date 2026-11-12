<?php

declare(strict_types=1);

require_once __DIR__ . '/aiConfig.php';

final class OpenAiServiceException extends RuntimeException
{
    public ?string $providerRequestId;
    public ?string $providerErrorCode;

    public function __construct(
        string $message,
        int $httpStatus = 503,
        ?string $providerRequestId = null,
        ?string $providerErrorCode = null
    ) {
        parent::__construct($message, $httpStatus);
        $this->providerRequestId = $providerRequestId;
        $this->providerErrorCode = $providerErrorCode;
    }
}

function assertOpenAiConfigured(): void
{
    if (!aiEnabled()) {
        throw new OpenAiServiceException('AI service is not enabled.', 503);
    }

    if (aiProvider() !== 'openai') {
        throw new OpenAiServiceException('AI provider is unavailable.', 503);
    }

    $key = openAiApiKey();
    if ($key === '' || strlen($key) < 20 || str_contains(strtolower($key), 'replace')) {
        throw new OpenAiServiceException('AI service is not configured.', 503);
    }
}

function validatedOpenAiText(string $value, string $field, int $maxChars): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException($field . ' is required.');
    }

    if (mb_strlen($value) > $maxChars) {
        throw new InvalidArgumentException($field . ' exceeds the configured AI input limit.');
    }

    return $value;
}

function openAiRequestHeaders(): array
{
    $headers = [
        'Authorization: Bearer ' . openAiApiKey(),
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Lambert-Electromec-AI/1.0',
    ];

    $organization = openAiOrganization();
    if ($organization !== '') {
        $headers[] = 'OpenAI-Organization: ' . $organization;
    }

    $project = openAiProject();
    if ($project !== '') {
        $headers[] = 'OpenAI-Project: ' . $project;
    }

    return $headers;
}

function openAiResponseText(array $response): array
{
    $text = [];
    $refusals = [];

    foreach (($response['output'] ?? []) as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'message') {
            continue;
        }

        foreach (($item['content'] ?? []) as $content) {
            if (!is_array($content)) {
                continue;
            }

            if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                $text[] = trim((string) $content['text']);
            }

            if (($content['type'] ?? '') === 'refusal' && is_string($content['refusal'] ?? null)) {
                $refusals[] = trim((string) $content['refusal']);
            }
        }
    }

    return [
        'text' => trim(implode("\n", array_filter($text))),
        'refusal' => trim(implode("\n", array_filter($refusals))),
    ];
}

function openAiShouldRetry(int $statusCode, int $curlErrorNumber): bool
{
    if ($curlErrorNumber !== 0) {
        return in_array($curlErrorNumber, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_RECV_ERROR], true);
    }

    return in_array($statusCode, [408, 409, 429], true) || $statusCode >= 500;
}

function openAiFailureStatus(int $statusCode, int $curlErrorNumber): int
{
    if ($curlErrorNumber === CURLE_OPERATION_TIMEDOUT || $statusCode === 408) {
        return 504;
    }

    return 503;
}

function openAiProviderError(array $decoded): array
{
    $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

    return [
        'code' => is_string($error['code'] ?? null) ? $error['code'] : null,
        'type' => is_string($error['type'] ?? null) ? $error['type'] : null,
    ];
}

/**
 * Creates a non-streaming OpenAI Responses API text response.
 *
 * The service always sends store=false. It does not expose raw provider error
 * bodies to callers and does not log prompts, user text or API credentials.
 */
function openAiCreateTextResponse(string $instructions, string $input, array $options = []): array
{
    assertOpenAiConfigured();

    $maxInputChars = openAiMaxInputChars();
    $instructions = validatedOpenAiText($instructions, 'AI instructions', $maxInputChars);
    $input = validatedOpenAiText($input, 'AI input', $maxInputChars);

    if (mb_strlen($instructions) + mb_strlen($input) > $maxInputChars) {
        throw new InvalidArgumentException('Combined AI input exceeds the configured limit.');
    }

    $model = trim((string) ($options['model'] ?? openAiModel()));
    if ($model === '' || preg_match('/^[A-Za-z0-9._:-]{2,100}$/', $model) !== 1) {
        throw new InvalidArgumentException('AI model is invalid.');
    }

    $maxOutputTokens = isset($options['max_output_tokens'])
        ? max(32, min(8000, (int) $options['max_output_tokens']))
        : openAiMaxOutputTokens();

    $reasoningEffort = strtolower(trim((string) ($options['reasoning_effort'] ?? openAiReasoningEffort())));
    if (!in_array($reasoningEffort, ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
        $reasoningEffort = openAiReasoningEffort();
    }

    $payload = [
        'model' => $model,
        'instructions' => $instructions,
        'input' => $input,
        'max_output_tokens' => $maxOutputTokens,
        'store' => false,
        'reasoning' => [
            'effort' => $reasoningEffort,
        ],
    ];

    $textFormat = $options['text_format'] ?? null;
    if ($textFormat !== null) {
        if (!is_array($textFormat) || ($textFormat['type'] ?? '') !== 'json_schema') {
            throw new InvalidArgumentException('AI structured output format is invalid.');
        }

        $name = trim((string) ($textFormat['name'] ?? ''));
        $schema = $textFormat['schema'] ?? null;
        if ($name === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1 || !is_array($schema)) {
            throw new InvalidArgumentException('AI structured output schema is invalid.');
        }

        $payload['text'] = [
            'format' => [
                'type' => 'json_schema',
                'name' => $name,
                'strict' => (bool) ($textFormat['strict'] ?? true),
                'schema' => $schema,
            ],
        ];
    }

    $safetyIdentifier = trim((string) ($options['safety_identifier'] ?? ''));
    if ($safetyIdentifier !== '') {
        $payload['safety_identifier'] = mb_substr($safetyIdentifier, 0, 128);
    }

    $metadata = $options['metadata'] ?? null;
    if (is_array($metadata) && $metadata !== []) {
        $safeMetadata = [];
        foreach (array_slice($metadata, 0, 16, true) as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $safeKey = mb_substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string) $key) ?? '', 0, 64);
            if ($safeKey === '') {
                continue;
            }
            $safeMetadata[$safeKey] = mb_substr((string) $value, 0, 512);
        }
        if ($safeMetadata !== []) {
            $payload['metadata'] = $safeMetadata;
        }
    }

    try {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (JsonException $error) {
        throw new OpenAiServiceException('AI request could not be prepared.', 500, null, 'json_encode');
    }

    $maxAttempts = openAiRetryCount() + 1;
    $lastStatusCode = 0;
    $lastCurlError = 0;
    $lastRequestId = null;
    $lastProviderCode = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $responseBody = '';
        $responseHeaders = [];
        $responseTooLarge = false;

        $curl = curl_init(openAiApiUrl());
        if ($curl === false) {
            throw new OpenAiServiceException('AI service is temporarily unavailable.', 503);
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => openAiRequestHeaders(),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => openAiConnectTimeoutSeconds(),
            CURLOPT_TIMEOUT => openAiTimeoutSeconds(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$responseTooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > openAiMaxResponseBytes()) {
                    $responseTooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);

        $executed = curl_exec($curl);
        $lastCurlError = curl_errno($curl);
        $lastStatusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $lastRequestId = $responseHeaders['x-request-id'] ?? null;
        curl_close($curl);

        if ($executed === false && $lastCurlError === CURLE_WRITE_ERROR && $responseTooLarge) {
            error_log('Lambert AI provider response exceeded configured maximum response size.');
            throw new OpenAiServiceException('AI service returned an invalid response.', 502, $lastRequestId, 'response_too_large');
        }

        $decoded = [];
        if ($responseBody !== '') {
            try {
                $parsed = json_decode($responseBody, true, 128, JSON_THROW_ON_ERROR);
                if (is_array($parsed)) {
                    $decoded = $parsed;
                }
            } catch (JsonException) {
                $decoded = [];
            }
        }

        $providerError = openAiProviderError($decoded);
        $lastProviderCode = $providerError['code'] ?? $providerError['type'] ?? null;

        if ($lastCurlError === 0 && $lastStatusCode >= 200 && $lastStatusCode < 300 && $decoded !== []) {
            $content = openAiResponseText($decoded);
            $status = (string) ($decoded['status'] ?? '');

            if ($status === 'failed') {
                throw new OpenAiServiceException('AI service could not complete the request.', 503, $lastRequestId, $lastProviderCode);
            }

            if ($content['text'] === '' && $content['refusal'] === '') {
                throw new OpenAiServiceException('AI service returned no usable text.', 502, $lastRequestId, 'empty_output');
            }

            return [
                'provider' => 'openai',
                'response_id' => is_string($decoded['id'] ?? null) ? $decoded['id'] : null,
                'provider_request_id' => $lastRequestId,
                'model' => is_string($decoded['model'] ?? null) ? $decoded['model'] : $model,
                'status' => $status !== '' ? $status : 'completed',
                'text' => $content['text'],
                'refusal' => $content['refusal'],
                'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
                'incomplete_details' => is_array($decoded['incomplete_details'] ?? null)
                    ? $decoded['incomplete_details']
                    : null,
            ];
        }

        if ($attempt < $maxAttempts && openAiShouldRetry($lastStatusCode, $lastCurlError)) {
            usleep(250000 * $attempt);
            continue;
        }

        error_log(sprintf(
            'Lambert OpenAI request failed: http=%d curl=%d request_id=%s provider_code=%s',
            $lastStatusCode,
            $lastCurlError,
            $lastRequestId ?? 'none',
            $lastProviderCode ?? 'none'
        ));

        throw new OpenAiServiceException(
            'AI service is temporarily unavailable.',
            openAiFailureStatus($lastStatusCode, $lastCurlError),
            $lastRequestId,
            $lastProviderCode
        );
    }

    throw new OpenAiServiceException('AI service is temporarily unavailable.', 503, $lastRequestId, $lastProviderCode);
}
