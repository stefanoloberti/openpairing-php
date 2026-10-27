#!/usr/bin/env php
<?php

declare(strict_types=1);

$batchPath = $argv[1] ?? null;
$candidatePath = $argv[2] ?? null;
$artifactRoot = $argv[3] ?? null;

try {
    if (!is_string($batchPath) || !is_string($candidatePath) || !is_string($artifactRoot)) {
        throw new RuntimeException('Usage: verify_thp_mode_probes.php BATCH.json CANDIDATE.json ARTIFACT_ROOT');
    }
    $identity = thpModeRunVerifier([
        PHP_BINARY,
        __DIR__.'/verify_thp_candidate_identity.php',
        $candidatePath,
        $artifactRoot,
    ]);
    $candidateIdentity = thpModeRequireSha256($identity['candidateIdentitySha256'] ?? null, 'verified candidateIdentitySha256');
    $candidate = thpModeDecodeFile($candidatePath, 'candidate identity');
    $product = thpModeRequireObject($candidate['product'] ?? null, 'candidate.product');
    $standards = thpModeRequireObject($candidate['standards'] ?? null, 'candidate.standards');
    $expectedCommit = thpModeRequirePattern($product['sourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'candidate.product.sourceCommit');
    $expectedMode = thpModeRequireBoundedString($product['fideMode'] ?? null, 'candidate.product.fideMode', 128);
    $expectedSystems = thpModeRequireUniqueStrings($standards['pairingSystems'] ?? null, 'candidate.standards.pairingSystems');

    $batch = thpModeDecodeFile($batchPath, 'mode probe batch');
    thpModeRejectPrivateKeys($batch);
    thpModeRequireExactKeys($batch, [
        'schema', 'candidateIdentitySha256', 'productSourceCommit', 'migrationManifestSha256',
        'fideMode', 'declaredPairingSystems', 'scenarios',
    ], 'batch');
    if (($batch['schema'] ?? null) !== 'openpairing.thp-mode-probe-batch/1') {
        throw new RuntimeException('Mode probe batch has an unsupported schema.');
    }
    if (!hash_equals($candidateIdentity, thpModeRequireSha256($batch['candidateIdentitySha256'] ?? null, 'candidateIdentitySha256'))) {
        throw new RuntimeException('Mode probe batch is not bound to the verified candidate identity.');
    }
    if ($expectedCommit !== thpModeRequirePattern($batch['productSourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'productSourceCommit')) {
        throw new RuntimeException('Mode probe batch product source commit differs from the verified candidate.');
    }
    thpModeRequireSha256($batch['migrationManifestSha256'] ?? null, 'migrationManifestSha256');
    if ($expectedMode !== thpModeRequireBoundedString($batch['fideMode'] ?? null, 'fideMode', 128)) {
        throw new RuntimeException('Mode probe batch FIDE mode differs from the verified candidate.');
    }
    $declaredSystems = thpModeRequireUniqueStrings($batch['declaredPairingSystems'] ?? null, 'declaredPairingSystems');
    if ($declaredSystems !== $expectedSystems) {
        throw new RuntimeException('Mode probe batch pairing systems differ from the verified candidate, including order.');
    }

    $rawScenarios = $batch['scenarios'] ?? null;
    $expectedCount = count($declaredSystems) + 4;
    if (!is_array($rawScenarios) || !array_is_list($rawScenarios) || count($rawScenarios) !== $expectedCount) {
        throw new RuntimeException(sprintf('scenarios must contain one case per declared system plus four rejection cases (%d total).', $expectedCount));
    }
    $declaredCases = [];
    $rejectionCases = [];
    $ids = [];
    foreach ($rawScenarios as $index => $rawScenario) {
        $scenario = thpModeRequireScenario($rawScenario, sprintf('scenarios[%d]', $index));
        if (isset($ids[$scenario['scenarioId']])) {
            throw new RuntimeException('Duplicate mode probe scenarioId: '.$scenario['scenarioId'].'.');
        }
        $ids[$scenario['scenarioId']] = true;
        if (!hash_equals($candidateIdentity, $scenario['candidateIdentitySha256'])) {
            throw new RuntimeException('Mode probe scenario is not bound to the verified candidate: '.$scenario['scenarioId'].'.');
        }
        if ($scenario['kind'] === 'declared-system') {
            if (isset($declaredCases[$scenario['requestedSystem']])) {
                throw new RuntimeException('Duplicate declared-system probe: '.$scenario['requestedSystem'].'.');
            }
            $declaredCases[$scenario['requestedSystem']] = $scenario;
        } else {
            if (isset($rejectionCases[$scenario['kind']])) {
                throw new RuntimeException('Duplicate rejection probe kind: '.$scenario['kind'].'.');
            }
            $rejectionCases[$scenario['kind']] = $scenario;
        }
    }
    foreach ($declaredSystems as $system) {
        if (!isset($declaredCases[$system])) {
            throw new RuntimeException('Missing declared-system probe: '.$system.'.');
        }
    }
    $requiredRejections = ['unknown-system', 'team-input', 'record-299', 'undeclared-feature'];
    foreach ($requiredRejections as $kind) {
        if (!isset($rejectionCases[$kind])) {
            throw new RuntimeException('Missing rejection probe kind: '.$kind.'.');
        }
    }

    $violations = [];
    foreach ($declaredSystems as $system) {
        thpModeCheck($violations, $declaredCases[$system], [
            'requestedMode' => $expectedMode,
            'requestedSystem' => $system,
            'inputKind' => 'individual',
            'unsupportedMarker' => 'none',
            'engineInvocationCount' => 1,
            'selectedSystem' => $system,
            'disposition' => 'accepted',
            'diagnosticCode' => null,
        ]);
    }
    $firstSystem = $declaredSystems[0];
    $unknown = $rejectionCases['unknown-system'];
    if (in_array($unknown['requestedSystem'], $declaredSystems, true)) {
        $violations[] = 'unknown-system.requested_system_is_declared';
    }
    thpModeCheck($violations, $unknown, [
        'requestedMode' => $expectedMode,
        'requestedSystem' => $unknown['requestedSystem'],
        'inputKind' => 'individual',
        'unsupportedMarker' => 'none',
        'engineInvocationCount' => 0,
        'selectedSystem' => null,
        'disposition' => 'rejected-unsupported',
        'diagnosticCode' => 'fide_mode.system_unsupported',
    ]);
    thpModeCheck($violations, $rejectionCases['team-input'], [
        'requestedMode' => $expectedMode,
        'requestedSystem' => $firstSystem,
        'inputKind' => 'team',
        'unsupportedMarker' => 'none',
        'engineInvocationCount' => 0,
        'selectedSystem' => null,
        'disposition' => 'rejected-unsupported',
        'diagnosticCode' => 'fide_mode.team_unsupported',
    ]);
    thpModeCheck($violations, $rejectionCases['record-299'], [
        'requestedMode' => $expectedMode,
        'requestedSystem' => $firstSystem,
        'inputKind' => 'individual',
        'unsupportedMarker' => 'record-299',
        'engineInvocationCount' => 0,
        'selectedSystem' => null,
        'disposition' => 'rejected-unsupported',
        'diagnosticCode' => 'trf.record_299_unsupported',
    ]);
    thpModeCheck($violations, $rejectionCases['undeclared-feature'], [
        'requestedMode' => $expectedMode,
        'requestedSystem' => $firstSystem,
        'inputKind' => 'individual',
        'unsupportedMarker' => 'undeclared-feature',
        'engineInvocationCount' => 0,
        'selectedSystem' => null,
        'disposition' => 'rejected-unsupported',
        'diagnosticCode' => 'fide_mode.feature_unsupported',
    ]);

    foreach (array_merge(array_values($declaredCases), array_values($rejectionCases)) as $scenario) {
        $prefix = $scenario['kind'].'.'.$scenario['scenarioId'];
        if ($scenario['legacyFallbackInvocationCount'] !== 0) {
            $violations[] = $prefix.'.legacy_fallback';
        }
        if ($scenario['manualFallbackInvocationCount'] !== 0) {
            $violations[] = $prefix.'.manual_fallback';
        }
        if ($scenario['writeCount'] !== 0) {
            $violations[] = $prefix.'.unexpected_write';
        }
    }
    $violations = array_values(array_unique($violations));
    $status = $violations === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.thp-mode-probe-verification/1',
        'status' => $status,
        'candidateIdentitySha256' => $candidateIdentity,
        'productSourceCommit' => $expectedCommit,
        'migrationManifestSha256' => $batch['migrationManifestSha256'],
        'fideMode' => $expectedMode,
        'declaredPairingSystems' => $declaredSystems,
        'scenarioCount' => $expectedCount,
        'blockingCodes' => $violations,
        'batchSha256' => hash_file('sha256', $batchPath),
        'boundary' => [
            'productModeInvoked' => false,
            'engineInvoked' => false,
            'databaseAccessPerformed' => false,
            'databaseMutationPerformed' => false,
            'productionMutationPerformed' => false,
            'submissionGateClosed' => false,
            'note' => 'GO validates complete supplied mode-selection records. The exact product harness must exercise the UI/API route and independently count engine, fallback and write calls.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-mode-probe-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_mode_probe.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/**
 * @param list<string> $command
 * @return array<string, mixed>
 */
function thpModeRunVerifier(array $command): array
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
    return thpModeDecodeJson((string) $stdout, 'candidate identity verification');
}

/** @return array<string, mixed> */
function thpModeDecodeFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    $size = filesize($path);
    if (!is_int($size) || $size > 8_388_608) {
        throw new RuntimeException($label.' exceeds the 8 MiB verification limit.');
    }
    return thpModeDecodeJson((string) file_get_contents($path), $label);
}

/** @return array<string, mixed> */
function thpModeDecodeJson(string $json, string $label): array
{
    $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    return thpModeRequireObject($decoded, $label);
}

/** @return array<string, mixed> */
function thpModeRequireObject(mixed $value, string $label): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException($label.' must be an object.');
    }
    /** @var array<string, mixed> $value */
    return $value;
}

/**
 * @param array<string, mixed> $value
 * @param list<string> $keys
 */
function thpModeRequireExactKeys(array $value, array $keys, string $label): void
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

/**
 * @return array{
 *   scenarioId:string,kind:string,candidateIdentitySha256:string,requestedMode:string,requestedSystem:string,
 *   inputKind:string,unsupportedMarker:string,engineInvocationCount:int,selectedSystem:?string,
 *   legacyFallbackInvocationCount:int,manualFallbackInvocationCount:int,writeCount:int,
 *   disposition:string,diagnosticCode:?string
 * }
 */
function thpModeRequireScenario(mixed $value, string $label): array
{
    $scenario = thpModeRequireObject($value, $label);
    thpModeRequireExactKeys($scenario, [
        'scenarioId', 'kind', 'candidateIdentitySha256', 'requestedMode', 'requestedSystem',
        'inputKind', 'unsupportedMarker', 'engineInvocationCount', 'selectedSystem',
        'legacyFallbackInvocationCount', 'manualFallbackInvocationCount', 'writeCount',
        'disposition', 'diagnosticCode',
    ], $label);
    return [
        'scenarioId' => thpModeRequirePattern($scenario['scenarioId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.scenarioId'),
        'kind' => thpModeRequireEnum($scenario['kind'] ?? null, ['declared-system', 'unknown-system', 'team-input', 'record-299', 'undeclared-feature'], $label.'.kind'),
        'candidateIdentitySha256' => thpModeRequireSha256($scenario['candidateIdentitySha256'] ?? null, $label.'.candidateIdentitySha256'),
        'requestedMode' => thpModeRequireBoundedString($scenario['requestedMode'] ?? null, $label.'.requestedMode', 128),
        'requestedSystem' => thpModeRequireBoundedString($scenario['requestedSystem'] ?? null, $label.'.requestedSystem', 128),
        'inputKind' => thpModeRequireEnum($scenario['inputKind'] ?? null, ['individual', 'team'], $label.'.inputKind'),
        'unsupportedMarker' => thpModeRequireEnum($scenario['unsupportedMarker'] ?? null, ['none', 'record-299', 'undeclared-feature'], $label.'.unsupportedMarker'),
        'engineInvocationCount' => thpModeRequireZeroOrOne($scenario['engineInvocationCount'] ?? null, $label.'.engineInvocationCount'),
        'selectedSystem' => thpModeRequireNullableString($scenario['selectedSystem'] ?? null, $label.'.selectedSystem'),
        'legacyFallbackInvocationCount' => thpModeRequireZeroOrOne($scenario['legacyFallbackInvocationCount'] ?? null, $label.'.legacyFallbackInvocationCount'),
        'manualFallbackInvocationCount' => thpModeRequireZeroOrOne($scenario['manualFallbackInvocationCount'] ?? null, $label.'.manualFallbackInvocationCount'),
        'writeCount' => thpModeRequireZeroOrOne($scenario['writeCount'] ?? null, $label.'.writeCount'),
        'disposition' => thpModeRequireEnum($scenario['disposition'] ?? null, ['accepted', 'rejected-unsupported'], $label.'.disposition'),
        'diagnosticCode' => thpModeRequireNullableString($scenario['diagnosticCode'] ?? null, $label.'.diagnosticCode'),
    ];
}

/**
 * @param list<string> $violations
 * @param array<string, mixed> $scenario
 * @param array<string, mixed> $expected
 */
function thpModeCheck(array &$violations, array $scenario, array $expected): void
{
    $kind = $scenario['kind'] ?? null;
    $scenarioId = $scenario['scenarioId'] ?? null;
    if (!is_string($kind) || !is_string($scenarioId)) {
        throw new RuntimeException('Validated mode scenario lacks its kind or scenarioId.');
    }
    foreach ($expected as $field => $value) {
        if (($scenario[$field] ?? null) !== $value) {
            $violations[] = $kind.'.'.$scenarioId.'.'.$field;
        }
    }
}

function thpModeRequireString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function thpModeRequireBoundedString(mixed $value, string $label, int $limit): string
{
    $value = thpModeRequireString($value, $label);
    if (strlen($value) > $limit) {
        throw new RuntimeException($label.' exceeds its byte limit.');
    }
    return $value;
}

function thpModeRequirePattern(mixed $value, string $pattern, string $label): string
{
    $value = thpModeRequireString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' has an invalid format.');
    }
    return $value;
}

function thpModeRequireSha256(mixed $value, string $label): string
{
    return thpModeRequirePattern($value, '/^[a-f0-9]{64}$/D', $label);
}

/** @param list<string> $allowed */
function thpModeRequireEnum(mixed $value, array $allowed, string $label): string
{
    $value = thpModeRequireString($value, $label);
    if (!in_array($value, $allowed, true)) {
        throw new RuntimeException($label.' has an unsupported value.');
    }
    return $value;
}

function thpModeRequireNullableString(mixed $value, string $label): ?string
{
    return $value === null ? null : thpModeRequireBoundedString($value, $label, 128);
}

function thpModeRequireZeroOrOne(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0 || $value > 1) {
        throw new RuntimeException($label.' must be zero or one.');
    }
    return $value;
}

/** @return list<string> */
function thpModeRequireUniqueStrings(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 60) {
        throw new RuntimeException($label.' must be a bounded non-empty list.');
    }
    $result = [];
    foreach ($value as $index => $item) {
        $item = thpModeRequireBoundedString($item, sprintf('%s[%d]', $label, $index), 128);
        if (in_array($item, $result, true)) {
            throw new RuntimeException($label.' contains a duplicate value.');
        }
        $result[] = $item;
    }
    return $result;
}

function thpModeRejectPrivateKeys(mixed $value, string $path = 'batch'): void
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
        thpModeRejectPrivateKeys($item, $path.'.'.$keyText);
    }
}
