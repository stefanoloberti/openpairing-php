#!/usr/bin/env php
<?php

declare(strict_types=1);

const OPERATOR_CAPABILITIES = [
    'activate-fide-mode',
    'import-rating-list',
    'create-tournament',
    'register-players',
    'generate-pairing',
    'enter-results',
    'calculate-standings',
    'export-trf',
    'recover-workflow',
];
const OPERATOR_WRITE_CAPABILITIES = [
    'import-rating-list',
    'create-tournament',
    'register-players',
    'generate-pairing',
    'enter-results',
];

$batchPath = $argv[1] ?? null;
$candidatePath = $argv[2] ?? null;
$artifactRoot = $argv[3] ?? null;

try {
    if (!is_string($batchPath) || !is_string($candidatePath) || !is_string($artifactRoot)) {
        throw new RuntimeException('Usage: verify_thp_operator_workflow_probes.php BATCH.json CANDIDATE.json ARTIFACT_ROOT');
    }
    $identity = operatorRunVerifier([
        PHP_BINARY, __DIR__.'/verify_thp_candidate_identity.php', $candidatePath, $artifactRoot,
    ]);
    $candidateIdentity = operatorSha($identity['candidateIdentitySha256'] ?? null, 'verified candidateIdentitySha256');
    $candidate = operatorDecodeFile($candidatePath, 'candidate identity');
    $product = operatorObject($candidate['product'] ?? null, 'candidate.product');
    $standards = operatorObject($candidate['standards'] ?? null, 'candidate.standards');
    $expectedCommit = operatorPattern($product['sourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'candidate.product.sourceCommit');
    $expectedMode = operatorBoundedString($product['fideMode'] ?? null, 'candidate.product.fideMode', 128);
    $expectedSystems = operatorUniqueStrings($standards['pairingSystems'] ?? null, 'candidate.standards.pairingSystems');
    $expectedTrfVersions = operatorUniqueStrings($standards['trfVersions'] ?? null, 'candidate.standards.trfVersions');

    $batch = operatorDecodeFile($batchPath, 'operator workflow probe batch');
    operatorRejectPrivateKeys($batch);
    operatorExactKeys($batch, [
        'schema', 'candidateIdentitySha256', 'productSourceCommit', 'migrationManifestSha256',
        'fideMode', 'pairingSystem', 'trfVersion', 'syntheticPlayerCount', 'roundsCompleted',
        'finalStandingsSha256', 'exportArtifactSha256', 'steps',
    ], 'batch');
    if (($batch['schema'] ?? null) !== 'openpairing.thp-operator-workflow-probe-batch/1') {
        throw new RuntimeException('Operator workflow probe batch has an unsupported schema.');
    }
    if (!hash_equals($candidateIdentity, operatorSha($batch['candidateIdentitySha256'] ?? null, 'candidateIdentitySha256'))) {
        throw new RuntimeException('Operator workflow probe batch is not bound to the verified candidate identity.');
    }
    if ($expectedCommit !== operatorPattern($batch['productSourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'productSourceCommit')) {
        throw new RuntimeException('Operator workflow probe batch product source commit differs from the verified candidate.');
    }
    operatorSha($batch['migrationManifestSha256'] ?? null, 'migrationManifestSha256');
    if ($expectedMode !== operatorBoundedString($batch['fideMode'] ?? null, 'fideMode', 128)) {
        throw new RuntimeException('Operator workflow probe batch FIDE mode differs from the verified candidate.');
    }
    $pairingSystem = operatorBoundedString($batch['pairingSystem'] ?? null, 'pairingSystem', 128);
    if (!in_array($pairingSystem, $expectedSystems, true)) {
        throw new RuntimeException('Operator workflow probe batch uses an undeclared pairing system.');
    }
    $trfVersion = operatorBoundedString($batch['trfVersion'] ?? null, 'trfVersion', 128);
    if (!in_array($trfVersion, $expectedTrfVersions, true)) {
        throw new RuntimeException('Operator workflow probe batch uses an undeclared TRF version.');
    }
    $players = operatorPositiveInt($batch['syntheticPlayerCount'] ?? null, 'syntheticPlayerCount', 4);
    $rounds = operatorPositiveInt($batch['roundsCompleted'] ?? null, 'roundsCompleted', 1);
    $standingsSha = operatorSha($batch['finalStandingsSha256'] ?? null, 'finalStandingsSha256');
    $exportSha = operatorSha($batch['exportArtifactSha256'] ?? null, 'exportArtifactSha256');
    if (hash_equals($standingsSha, $exportSha)) {
        throw new RuntimeException('Standings and TRF export hashes must identify distinct functional artifacts.');
    }

    $rawSteps = $batch['steps'] ?? null;
    if (!is_array($rawSteps) || !array_is_list($rawSteps) || count($rawSteps) !== count(OPERATOR_CAPABILITIES)) {
        throw new RuntimeException('steps must contain exactly the nine required operator capabilities.');
    }
    $steps = [];
    $ids = [];
    foreach ($rawSteps as $index => $rawStep) {
        $step = operatorStep($rawStep, sprintf('steps[%d]', $index));
        if (isset($ids[$step['stepId']])) {
            throw new RuntimeException('Duplicate operator workflow stepId: '.$step['stepId'].'.');
        }
        if ($step['capability'] !== OPERATOR_CAPABILITIES[$index]) {
            throw new RuntimeException(sprintf('Operator workflow capability at index %d must be %s.', $index, OPERATOR_CAPABILITIES[$index]));
        }
        if ($index > 0 && !hash_equals($steps[$index - 1]['stateSha256After'], $step['stateSha256Before'])) {
            throw new RuntimeException('Operator workflow state chain is discontinuous before '.$step['capability'].'.');
        }
        $ids[$step['stepId']] = true;
        $steps[] = $step;
    }

    $violations = [];
    $englishAssertions = 0;
    foreach ($steps as $step) {
        $capability = $step['capability'];
        $englishAssertions += $step['englishAssertionCount'];
        if ($step['uiLocale'] !== 'en') {
            $violations[] = $capability.'.locale';
        }
        if ($step['englishAssertionCount'] < 1) {
            $violations[] = $capability.'.english_assertions';
        }
        if ($step['nonEnglishLabelCount'] !== 0) {
            $violations[] = $capability.'.non_english_labels';
        }
        if ($step['missingHelpLinkCount'] !== 0) {
            $violations[] = $capability.'.missing_help';
        }
        if ($step['legacyFallbackInvocationCount'] !== 0 || $step['manualFallbackInvocationCount'] !== 0) {
            $violations[] = $capability.'.fallback';
        }
        if ($step['publicationCount'] !== 0) {
            $violations[] = $capability.'.publication';
        }
        if ($step['outcome'] !== 'success' || $step['diagnosticCode'] !== null) {
            $violations[] = $capability.'.outcome';
        }
        $writes = in_array($capability, OPERATOR_WRITE_CAPABILITIES, true);
        $expectedTransaction = $writes ? 1 : 0;
        if ($step['transactionBeginCount'] !== $expectedTransaction
            || $step['transactionCommitCount'] !== $expectedTransaction
            || $step['transactionRollbackCount'] !== 0) {
            $violations[] = $capability.'.transaction';
        }
        $stateChanged = !hash_equals($step['stateSha256Before'], $step['stateSha256After']);
        if ($stateChanged !== $writes) {
            $violations[] = $capability.'.state_transition';
        }
        $expectedEngineCalls = $capability === 'generate-pairing' ? 1 : 0;
        if ($step['engineInvocationCount'] !== $expectedEngineCalls) {
            $violations[] = $capability.'.engine_calls';
        }
    }
    if ($players < 4 || $rounds < 1) {
        $violations[] = 'workflow.vacuous_denominator';
    }

    $violations = array_values(array_unique($violations));
    sort($violations, SORT_STRING);
    $status = $violations === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.thp-operator-workflow-probe-verification/1',
        'status' => $status,
        'candidateIdentitySha256' => $candidateIdentity,
        'productSourceCommit' => $expectedCommit,
        'migrationManifestSha256' => $batch['migrationManifestSha256'],
        'fideMode' => $expectedMode,
        'pairingSystem' => $pairingSystem,
        'trfVersion' => $trfVersion,
        'syntheticPlayerCount' => $players,
        'roundsCompleted' => $rounds,
        'stepCount' => count($steps),
        'englishAssertionCount' => $englishAssertions,
        'blockingCodes' => $violations,
        'batchSha256' => hash_file('sha256', $batchPath),
        'boundary' => [
            'operatorWorkflowExecutedByVerifier' => false,
            'uiLanguageInspectedByVerifier' => false,
            'databaseAccessPerformed' => false,
            'databaseMutationPerformed' => false,
            'productionMutationPerformed' => false,
            'submissionGateClosed' => false,
            'note' => 'GO validates complete supplied synthetic product-harness records; it does not execute or visually inspect the ChessPairings workflow.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-operator-workflow-probe-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_operator_workflow_probe.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/**
 * @param list<string> $command
 * @return array<string, mixed>
 */
function operatorRunVerifier(array $command): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start candidate identity verifier.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Candidate identity verification failed: '.trim((string) $stderr));
    }
    return operatorDecodeJson((string) $stdout, 'candidate identity verification');
}

/** @return array<string, mixed> */
function operatorDecodeFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    $size = filesize($path);
    if (!is_int($size) || $size > 8_388_608) {
        throw new RuntimeException($label.' exceeds the 8 MiB verification limit.');
    }
    return operatorDecodeJson((string) file_get_contents($path), $label);
}

/** @return array<string, mixed> */
function operatorDecodeJson(string $json, string $label): array
{
    return operatorObject(json_decode($json, true, 64, JSON_THROW_ON_ERROR), $label);
}

/** @return array<string, mixed> */
function operatorObject(mixed $value, string $label): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException($label.' must be an object.');
    }
    /** @var array<string, mixed> $value */
    return $value;
}

/** @param array<string, mixed> $value @param list<string> $keys */
function operatorExactKeys(array $value, array $keys, string $label): void
{
    $missing = array_values(array_diff($keys, array_keys($value)));
    $unexpected = array_values(array_diff(array_keys($value), $keys));
    if ($missing !== []) {
        throw new RuntimeException($label.' lacks required fields: '.implode(', ', $missing).'.');
    }
    if ($unexpected !== []) {
        throw new RuntimeException($label.' contains unexpected fields: '.implode(', ', $unexpected).'.');
    }
}

/** @return array<string, mixed> */
function operatorStep(mixed $value, string $label): array
{
    $step = operatorObject($value, $label);
    operatorExactKeys($step, [
        'stepId', 'capability', 'uiLocale', 'screenContentSha256', 'helpTopicId', 'helpContentSha256',
        'englishAssertionCount', 'nonEnglishLabelCount', 'missingHelpLinkCount', 'stateSha256Before',
        'stateSha256After', 'engineInvocationCount', 'legacyFallbackInvocationCount',
        'manualFallbackInvocationCount', 'transactionBeginCount', 'transactionCommitCount',
        'transactionRollbackCount', 'publicationCount', 'outcome', 'diagnosticCode',
    ], $label);
    return [
        'stepId' => operatorPattern($step['stepId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.stepId'),
        'capability' => operatorEnum($step['capability'] ?? null, OPERATOR_CAPABILITIES, $label.'.capability'),
        'uiLocale' => operatorBoundedString($step['uiLocale'] ?? null, $label.'.uiLocale', 16),
        'screenContentSha256' => operatorSha($step['screenContentSha256'] ?? null, $label.'.screenContentSha256'),
        'helpTopicId' => operatorPattern($step['helpTopicId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $label.'.helpTopicId'),
        'helpContentSha256' => operatorSha($step['helpContentSha256'] ?? null, $label.'.helpContentSha256'),
        'englishAssertionCount' => operatorNonNegative($step['englishAssertionCount'] ?? null, $label.'.englishAssertionCount'),
        'nonEnglishLabelCount' => operatorNonNegative($step['nonEnglishLabelCount'] ?? null, $label.'.nonEnglishLabelCount'),
        'missingHelpLinkCount' => operatorNonNegative($step['missingHelpLinkCount'] ?? null, $label.'.missingHelpLinkCount'),
        'stateSha256Before' => operatorSha($step['stateSha256Before'] ?? null, $label.'.stateSha256Before'),
        'stateSha256After' => operatorSha($step['stateSha256After'] ?? null, $label.'.stateSha256After'),
        'engineInvocationCount' => operatorZeroOrOne($step['engineInvocationCount'] ?? null, $label.'.engineInvocationCount'),
        'legacyFallbackInvocationCount' => operatorZeroOrOne($step['legacyFallbackInvocationCount'] ?? null, $label.'.legacyFallbackInvocationCount'),
        'manualFallbackInvocationCount' => operatorZeroOrOne($step['manualFallbackInvocationCount'] ?? null, $label.'.manualFallbackInvocationCount'),
        'transactionBeginCount' => operatorZeroOrOne($step['transactionBeginCount'] ?? null, $label.'.transactionBeginCount'),
        'transactionCommitCount' => operatorZeroOrOne($step['transactionCommitCount'] ?? null, $label.'.transactionCommitCount'),
        'transactionRollbackCount' => operatorZeroOrOne($step['transactionRollbackCount'] ?? null, $label.'.transactionRollbackCount'),
        'publicationCount' => operatorZeroOrOne($step['publicationCount'] ?? null, $label.'.publicationCount'),
        'outcome' => operatorEnum($step['outcome'] ?? null, ['success', 'failure'], $label.'.outcome'),
        'diagnosticCode' => $step['diagnosticCode'] === null ? null : operatorBoundedString($step['diagnosticCode'], $label.'.diagnosticCode', 128),
    ];
}

function operatorString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function operatorBoundedString(mixed $value, string $label, int $limit): string
{
    $value = operatorString($value, $label);
    if (strlen($value) > $limit) {
        throw new RuntimeException($label.' exceeds its byte limit.');
    }
    return $value;
}

function operatorPattern(mixed $value, string $pattern, string $label): string
{
    $value = operatorString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' has an invalid format.');
    }
    return $value;
}

function operatorSha(mixed $value, string $label): string
{
    return operatorPattern($value, '/^[a-f0-9]{64}$/D', $label);
}

/** @param list<string> $allowed */
function operatorEnum(mixed $value, array $allowed, string $label): string
{
    $value = operatorString($value, $label);
    if (!in_array($value, $allowed, true)) {
        throw new RuntimeException($label.' has an unsupported value.');
    }
    return $value;
}

function operatorNonNegative(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException($label.' must be a non-negative integer.');
    }
    return $value;
}

function operatorPositiveInt(mixed $value, string $label, int $minimum): int
{
    if (!is_int($value) || $value < $minimum) {
        throw new RuntimeException(sprintf('%s must be an integer of at least %d.', $label, $minimum));
    }
    return $value;
}

function operatorZeroOrOne(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0 || $value > 1) {
        throw new RuntimeException($label.' must be zero or one.');
    }
    return $value;
}

/** @return list<string> */
function operatorUniqueStrings(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 60) {
        throw new RuntimeException($label.' must be a bounded non-empty list.');
    }
    $result = [];
    foreach ($value as $index => $item) {
        $item = operatorBoundedString($item, sprintf('%s[%d]', $label, $index), 128);
        if (in_array($item, $result, true)) {
            throw new RuntimeException($label.' contains a duplicate value.');
        }
        $result[] = $item;
    }
    return $result;
}

function operatorRejectPrivateKeys(mixed $value, string $path = 'batch'): void
{
    if (!is_array($value)) {
        return;
    }
    $forbidden = ['name', 'email', 'password', 'token', 'secret', 'fideid', 'federationid', 'birthdate'];
    foreach ($value as $key => $item) {
        $keyText = is_string($key) ? $key : (string) $key;
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $keyText));
        if (in_array($normalized, $forbidden, true)) {
            throw new RuntimeException($path.' contains forbidden personal/secret field '.$keyText.'.');
        }
        operatorRejectPrivateKeys($item, $path.'.'.$keyText);
    }
}
