<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This diagnostic must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/connection.php';
require_once $root . '/includes/aiAssistantService.php';

function diagnosticJson(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function diagnosticFunctionFile(string $functionName): void
{
    if (!function_exists($functionName)) {
        echo "[FAIL] Function not loaded: {$functionName}\n";
        return;
    }

    $reflection = new ReflectionFunction($functionName);
    $file = (string) $reflection->getFileName();
    $hash = is_file($file) ? hash_file('sha256', $file) : 'n/a';
    echo "{$functionName} loaded from: {$file}\n";
    echo "{$functionName} file SHA256: {$hash}\n";
}

function diagnosticBoundary(string $label, string $value, string $left, string $right): void
{
    $lower = mb_strtolower($value, 'UTF-8');
    $leftLower = mb_strtolower($left, 'UTF-8');
    $rightLower = mb_strtolower($right, 'UTF-8');

    $leftPos = mb_strpos($lower, $leftLower, 0, 'UTF-8');
    if ($leftPos === false) {
        echo "[INFO] {$label}: left token not found ({$left})\n";
        return;
    }

    $searchStart = $leftPos + mb_strlen($leftLower, 'UTF-8');
    $rightPos = mb_strpos($lower, $rightLower, $searchStart, 'UTF-8');
    if ($rightPos === false || ($rightPos - $searchStart) > 20) {
        echo "[INFO] {$label}: target pair not found close together ({$left} ... {$right})\n";
        return;
    }

    $between = mb_substr($value, $searchStart, $rightPos - $searchStart, 'UTF-8');
    echo "[BOUNDARY] {$label}: {$left}[" . diagnosticJson($between) . "]{$right}";
    echo " | bytes=" . bin2hex($between) . " | chars=" . mb_strlen($between, 'UTF-8') . "\n";
}

function diagnosticAnswer(string $label, string $answer): void
{
    echo "\n=== {$label} ===\n";
    echo "JSON: " . diagnosticJson($answer) . "\n";
    echo "Length: bytes=" . strlen($answer) . ", chars=" . mb_strlen($answer, 'UTF-8') . "\n";

    diagnosticBoundary($label . ' and/expertise', $answer, 'and', 'expertise');
    diagnosticBoundary($label . ' ensure/that', $answer, 'ensure', 'that');
    diagnosticBoundary($label . ' of/work', $answer, 'of', 'work');
    diagnosticBoundary($label . ' of/service', $answer, 'of', 'service');
    diagnosticBoundary($label . ' of/specialisation', $answer, 'of', 'specialisation');
    diagnosticBoundary($label . ' property/management', $answer, 'property', 'management');

    $normalized = aiTextNormalizeFinalAnswer($answer);
    echo 'Normalizer changes returned answer: ' . (($normalized === $answer) ? 'NO' : 'YES') . "\n";
    echo 'Known joined matches BEFORE: ' . diagnosticJson(aiTextKnownJoinedSpacingMatches($answer)) . "\n";
    echo 'Known joined matches AFTER:  ' . diagnosticJson(aiTextKnownJoinedSpacingMatches($normalized)) . "\n";

    if ($normalized !== $answer) {
        echo 'Normalized JSON: ' . diagnosticJson($normalized) . "\n";
    }
}

try {
    echo "Lambert AI spacing diagnostic v2\n";
    echo "PHP: " . PHP_VERSION . "\n\n";

    diagnosticFunctionFile('aiTextNormalizeFinalAnswer');
    diagnosticFunctionFile('createLambertWebsiteAssistantResponse');
    diagnosticFunctionFile('createLambertLocalFallbackAssistantResponse');

    $literal = 'andexpertise ensurethat areas ofwork areas ofservice areas ofspecialisation propertymanagement';
    $literalNormalized = aiTextNormalizeFinalAnswer($literal);
    echo "\nLiteral input:  " . diagnosticJson($literal) . "\n";
    echo "Literal output: " . diagnosticJson($literalNormalized) . "\n";
    echo "Literal repair: " . (($literalNormalized === 'and expertise ensure that areas of work areas of service areas of specialisation property management') ? 'PASS' : 'FAIL') . "\n";

    $keys = ['about.profile', 'corporate.iso-qms'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $sql = "SELECT knowledge_key, content FROM ai_knowledge_entries WHERE locale = 'en' AND knowledge_key IN ({$placeholders}) ORDER BY knowledge_key";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
    $stmt->execute();
    $resultSet = $stmt->get_result();
    while ($row = $resultSet->fetch_assoc()) {
        diagnosticAnswer('DB ' . (string) $row['knowledge_key'], (string) $row['content']);
    }
    $stmt->close();

    $message = 'What services does Lambert Electromec provide?';

    echo "\nCalling forced-local assistant...\n";
    $local = createLambertWebsiteAssistantResponse(
        $conn,
        validatedAiAssistantMessage($message),
        'en',
        [],
        'cli_spacing_diagnostic_local_v2',
        true
    );
    $localAnswer = (string) (($local['assistant']['answer'] ?? ''));
    diagnosticAnswer('LOCAL RETURNED ANSWER', $localAnswer);
    echo 'Local provider: ' . diagnosticJson($local['provider'] ?? []) . "\n";

    if (aiEnabled()) {
        echo "\nCalling OpenAI assistant...\n";
        $openAi = createLambertWebsiteAssistantResponse(
            $conn,
            validatedAiAssistantMessage($message),
            'en',
            [],
            'cli_spacing_diagnostic_openai_v2'
        );
        $openAiAnswer = (string) (($openAi['assistant']['answer'] ?? ''));
        diagnosticAnswer('OPENAI RETURNED ANSWER', $openAiAnswer);
        echo 'OpenAI provider: ' . diagnosticJson($openAi['provider'] ?? []) . "\n";
    } else {
        echo "\n[INFO] OpenAI is disabled; OpenAI diagnostic skipped.\n";
    }

    echo "\nDiagnostic complete.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . get_class($error) . ': ' . $error->getMessage() . "\n");
    fwrite(STDERR, 'At: ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
