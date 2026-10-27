#!/usr/bin/env php
<?php

declare(strict_types=1);

$batchPath = $argv[1] ?? null;

try {
    if (!is_string($batchPath)) {
        throw new RuntimeException('Usage: verify_thp_recovery_probes.php BATCH.json');
    }
    $batch = thpRecoveryDecodeFile($batchPath);
    thpRecoveryRejectPrivateKeys($batch);
    thpRecoveryRequireExactKeys($batch, [
        'schema', 'candidateIdentitySha256', 'productSourceCommit', 'migrationManifestSha256',
        'baselineRevision', 'newerRevision', 'baselineStateSha256', 'committedStateSha256',
        'newerRevisionStateSha256', 'scenarios',
    ], 'batch');
    if (($batch['schema'] ?? null) !== 'openpairing.thp-recovery-probe-batch/1') {
        throw new RuntimeException('Recovery probe batch has an unsupported schema.');
    }
    $candidateIdentity = thpRecoveryRequireSha256($batch['candidateIdentitySha256'] ?? null, 'candidateIdentitySha256');
    $productCommit = thpRecoveryRequirePattern($batch['productSourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'productSourceCommit');
    $migrationHash = thpRecoveryRequireSha256($batch['migrationManifestSha256'] ?? null, 'migrationManifestSha256');
    $baselineRevision = thpRecoveryRequireBoundedString($batch['baselineRevision'] ?? null, 'baselineRevision', 128);
    $newerRevision = thpRecoveryRequireBoundedString($batch['newerRevision'] ?? null, 'newerRevision', 128);
    if ($baselineRevision === $newerRevision) {
        throw new RuntimeException('newerRevision must differ from baselineRevision.');
    }
    $baselineHash = thpRecoveryRequireSha256($batch['baselineStateSha256'] ?? null, 'baselineStateSha256');
    $committedHash = thpRecoveryRequireSha256($batch['committedStateSha256'] ?? null, 'committedStateSha256');
    $newerHash = thpRecoveryRequireSha256($batch['newerRevisionStateSha256'] ?? null, 'newerRevisionStateSha256');
    if (count(array_unique([$baselineHash, $committedHash, $newerHash])) !== 3) {
        throw new RuntimeException('Baseline, committed and newer-revision state hashes must be distinct.');
    }

    $scenarioValues = $batch['scenarios'] ?? null;
    if (!is_array($scenarioValues) || !array_is_list($scenarioValues) || count($scenarioValues) !== 4) {
        throw new RuntimeException('scenarios must contain exactly the four required recovery cases.');
    }
    $scenarios = [];
    foreach ($scenarioValues as $index => $value) {
        $scenario = thpRecoveryRequireScenario($value, sprintf('scenarios[%d]', $index));
        if (isset($scenarios[$scenario['type']])) {
            throw new RuntimeException('Duplicate recovery scenario type: '.$scenario['type'].'.');
        }
        $scenarios[$scenario['type']] = $scenario;
    }
    $requiredTypes = ['crash-before-commit', 'crash-after-commit', 'late-completion', 'duplicate-delivery'];
    foreach ($requiredTypes as $type) {
        if (!isset($scenarios[$type])) {
            throw new RuntimeException('Missing recovery scenario type: '.$type.'.');
        }
    }

    $violations = [];
    thpRecoveryCheckScenario($violations, $scenarios['crash-before-commit'], [
        'requestRevision' => $baselineRevision,
        'currentRevision' => $baselineRevision,
        'hashes' => [$baselineHash, $baselineHash, $baselineHash, $baselineHash],
        'commitCount' => 0,
        'publicationCount' => 0,
        'resultDisposition' => 'discarded-uncommitted',
        'recoveryAction' => 'discard-uncommitted',
        'workerRestartObserved' => true,
        'durableJobReloaded' => true,
    ]);
    thpRecoveryCheckScenario($violations, $scenarios['crash-after-commit'], [
        'requestRevision' => $baselineRevision,
        'currentRevision' => $baselineRevision,
        'hashes' => [$baselineHash, $committedHash, $committedHash, $committedHash],
        'commitCount' => 1,
        'publicationCount' => 1,
        'resultDisposition' => 'already-committed',
        'recoveryAction' => 'recognize-committed',
        'workerRestartObserved' => true,
        'durableJobReloaded' => true,
    ]);
    thpRecoveryCheckScenario($violations, $scenarios['late-completion'], [
        'requestRevision' => $baselineRevision,
        'currentRevision' => $newerRevision,
        'hashes' => [$newerHash, $newerHash, $newerHash, $newerHash],
        'commitCount' => 0,
        'publicationCount' => 0,
        'resultDisposition' => 'rejected-stale',
        'recoveryAction' => 'reject-stale',
        'workerRestartObserved' => false,
        'durableJobReloaded' => true,
    ]);
    thpRecoveryCheckScenario($violations, $scenarios['duplicate-delivery'], [
        'requestRevision' => $baselineRevision,
        'currentRevision' => $baselineRevision,
        'hashes' => [$baselineHash, $committedHash, $committedHash, $committedHash],
        'commitCount' => 1,
        'publicationCount' => 1,
        'resultDisposition' => 'deduplicated',
        'recoveryAction' => 'deduplicate',
        'workerRestartObserved' => false,
        'durableJobReloaded' => true,
    ]);

    $status = $violations === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.thp-recovery-probe-verification/1',
        'status' => $status,
        'candidateIdentitySha256' => $candidateIdentity,
        'productSourceCommit' => $productCommit,
        'migrationManifestSha256' => $migrationHash,
        'batchSha256' => hash_file('sha256', $batchPath),
        'scenarioCount' => count($scenarios),
        'scenarioTypes' => $requiredTypes,
        'blockingCodes' => $violations,
        'boundary' => [
            'databaseAccessPerformed' => false,
            'workerRestartPerformed' => false,
            'probeHashesIndependentlyDerived' => false,
            'databaseMutationPerformed' => false,
            'productionMutationPerformed' => false,
            'submissionGateClosed' => false,
            'note' => 'GO validates complete supplied recovery records. The exact product harness must execute restarts, redelivery and stale-result probes against an isolated database.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-recovery-probe-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_recovery_probe.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/** @return array<string, mixed> */
function thpRecoveryDecodeFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Recovery probe batch does not exist.');
    }
    $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    return thpRecoveryRequireObject($decoded, 'batch');
}

/** @return array<string, mixed> */
function thpRecoveryRequireObject(mixed $value, string $label): array
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
function thpRecoveryRequireExactKeys(array $value, array $keys, string $label): void
{
    $missing = array_values(array_diff($keys, array_keys($value)));
    if ($missing !== []) {
        throw new RuntimeException($label.' lacks required fields: '.implode(', ', $missing).'.');
    }
    $unexpected = array_values(array_diff(array_keys($value), $keys));
    if ($unexpected !== []) {
        throw new RuntimeException($label.' contains unexpected fields: '.implode(', ', $unexpected).'.');
    }
}

/**
 * @return array{
 *   scenarioId: string, type: string, jobId: string, requestRevision: string, currentRevision: string,
 *   stateBeforeSha256: string, stateAfterFirstHandlingSha256: string, stateAfterRecoverySha256: string,
 *   stateAfterSecondRecoverySha256: string, commitCount: int, publicationCount: int,
 *   resultDisposition: string, recoveryAction: string, workerRestartObserved: bool,
 *   durableJobReloaded: bool, idempotent: bool
 * }
 */
function thpRecoveryRequireScenario(mixed $value, string $label): array
{
    $scenario = thpRecoveryRequireObject($value, $label);
    thpRecoveryRequireExactKeys($scenario, [
        'scenarioId', 'type', 'jobId', 'requestRevision', 'currentRevision', 'stateBeforeSha256',
        'stateAfterFirstHandlingSha256', 'stateAfterRecoverySha256', 'stateAfterSecondRecoverySha256',
        'commitCount', 'publicationCount', 'resultDisposition', 'recoveryAction', 'workerRestartObserved',
        'durableJobReloaded', 'idempotent',
    ], $label);
    return [
        'scenarioId' => thpRecoveryRequirePattern($scenario['scenarioId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.scenarioId'),
        'type' => thpRecoveryRequireEnum($scenario['type'] ?? null, ['crash-before-commit', 'crash-after-commit', 'late-completion', 'duplicate-delivery'], $label.'.type'),
        'jobId' => thpRecoveryRequirePattern($scenario['jobId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.jobId'),
        'requestRevision' => thpRecoveryRequireBoundedString($scenario['requestRevision'] ?? null, $label.'.requestRevision', 128),
        'currentRevision' => thpRecoveryRequireBoundedString($scenario['currentRevision'] ?? null, $label.'.currentRevision', 128),
        'stateBeforeSha256' => thpRecoveryRequireSha256($scenario['stateBeforeSha256'] ?? null, $label.'.stateBeforeSha256'),
        'stateAfterFirstHandlingSha256' => thpRecoveryRequireSha256($scenario['stateAfterFirstHandlingSha256'] ?? null, $label.'.stateAfterFirstHandlingSha256'),
        'stateAfterRecoverySha256' => thpRecoveryRequireSha256($scenario['stateAfterRecoverySha256'] ?? null, $label.'.stateAfterRecoverySha256'),
        'stateAfterSecondRecoverySha256' => thpRecoveryRequireSha256($scenario['stateAfterSecondRecoverySha256'] ?? null, $label.'.stateAfterSecondRecoverySha256'),
        'commitCount' => thpRecoveryRequireCount($scenario['commitCount'] ?? null, $label.'.commitCount'),
        'publicationCount' => thpRecoveryRequireCount($scenario['publicationCount'] ?? null, $label.'.publicationCount'),
        'resultDisposition' => thpRecoveryRequireEnum($scenario['resultDisposition'] ?? null, ['discarded-uncommitted', 'already-committed', 'rejected-stale', 'deduplicated'], $label.'.resultDisposition'),
        'recoveryAction' => thpRecoveryRequireEnum($scenario['recoveryAction'] ?? null, ['discard-uncommitted', 'recognize-committed', 'reject-stale', 'deduplicate'], $label.'.recoveryAction'),
        'workerRestartObserved' => thpRecoveryRequireBool($scenario['workerRestartObserved'] ?? null, $label.'.workerRestartObserved'),
        'durableJobReloaded' => thpRecoveryRequireBool($scenario['durableJobReloaded'] ?? null, $label.'.durableJobReloaded'),
        'idempotent' => thpRecoveryRequireBool($scenario['idempotent'] ?? null, $label.'.idempotent'),
    ];
}

/**
 * @param list<string> $violations
 * @param array<string, mixed> $scenario
 * @param array{
 *   requestRevision: string, currentRevision: string, hashes: list<string>, commitCount: int,
 *   publicationCount: int, resultDisposition: string, recoveryAction: string,
 *   workerRestartObserved: bool, durableJobReloaded: bool
 * } $expected
 */
function thpRecoveryCheckScenario(array &$violations, array $scenario, array $expected): void
{
    $typeValue = $scenario['type'] ?? null;
    if (!is_string($typeValue)) {
        throw new RuntimeException('Validated scenario lacks type.');
    }
    $type = $typeValue;
    thpRecoveryAddMismatch($violations, $scenario['requestRevision'] !== $expected['requestRevision'], $type.'.request_revision');
    thpRecoveryAddMismatch($violations, $scenario['currentRevision'] !== $expected['currentRevision'], $type.'.current_revision');
    $hashFields = ['stateBeforeSha256', 'stateAfterFirstHandlingSha256', 'stateAfterRecoverySha256', 'stateAfterSecondRecoverySha256'];
    foreach ($hashFields as $index => $field) {
        thpRecoveryAddMismatch($violations, $scenario[$field] !== $expected['hashes'][$index], $type.'.'.$field);
    }
    foreach (['commitCount', 'publicationCount', 'resultDisposition', 'recoveryAction', 'workerRestartObserved', 'durableJobReloaded'] as $field) {
        thpRecoveryAddMismatch($violations, $scenario[$field] !== $expected[$field], $type.'.'.$field);
    }
    thpRecoveryAddMismatch($violations, $scenario['idempotent'] !== true, $type.'.not_idempotent');
}

function thpRecoveryRequireString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function thpRecoveryRequireBoundedString(mixed $value, string $label, int $maxLength): string
{
    $value = thpRecoveryRequireString($value, $label);
    if (strlen($value) > $maxLength) {
        throw new RuntimeException(sprintf('%s exceeds %d bytes.', $label, $maxLength));
    }
    return $value;
}

function thpRecoveryRequirePattern(mixed $value, string $pattern, string $label): string
{
    $value = thpRecoveryRequireString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' has an invalid format.');
    }
    return $value;
}

function thpRecoveryRequireSha256(mixed $value, string $label): string
{
    return thpRecoveryRequirePattern($value, '/^[a-f0-9]{64}$/D', $label);
}

/** @param list<string> $allowed */
function thpRecoveryRequireEnum(mixed $value, array $allowed, string $label): string
{
    $value = thpRecoveryRequireString($value, $label);
    if (!in_array($value, $allowed, true)) {
        throw new RuntimeException($label.' has an unsupported value.');
    }
    return $value;
}

function thpRecoveryRequireBool(mixed $value, string $label): bool
{
    if (!is_bool($value)) {
        throw new RuntimeException($label.' must be boolean.');
    }
    return $value;
}

function thpRecoveryRequireCount(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0 || $value > 1) {
        throw new RuntimeException($label.' must be zero or one.');
    }
    return $value;
}

/** @param list<string> $violations */
function thpRecoveryAddMismatch(array &$violations, bool $condition, string $code): void
{
    if ($condition) {
        $violations[] = $code;
    }
}

function thpRecoveryRejectPrivateKeys(mixed $value): void
{
    if (!is_array($value)) {
        return;
    }
    $forbidden = ['player', 'players', 'playerid', 'name', 'email', 'fideid', 'federationid', 'birthdate', 'phone', 'address'];
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
            throw new RuntimeException('Recovery evidence contains forbidden personal-data key.');
        }
        thpRecoveryRejectPrivateKeys($item);
    }
}
