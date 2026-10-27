#!/usr/bin/env php
<?php

declare(strict_types=1);

$batchPath = $argv[1] ?? null;

try {
    if (!is_string($batchPath)) {
        throw new RuntimeException('Usage: verify_thp_transaction_probes.php BATCH.json');
    }
    $batch = thpTxDecodeFile($batchPath);
    thpTxRejectPrivateKeys($batch);
    thpTxRequireExactKeys($batch, [
        'schema', 'candidateIdentitySha256', 'productSourceCommit', 'migrationManifestSha256',
        'baselineRevision', 'committedRevision', 'baselineStateSha256', 'expectedCommittedStateSha256',
        'requiredWriteStages', 'successProbe', 'failureProbes',
    ], 'batch');
    if (($batch['schema'] ?? null) !== 'openpairing.thp-transaction-probe-batch/1') {
        throw new RuntimeException('Transaction probe batch has an unsupported schema.');
    }
    $candidateIdentity = thpTxRequireSha256($batch['candidateIdentitySha256'] ?? null, 'candidateIdentitySha256');
    $productCommit = thpTxRequirePattern($batch['productSourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'productSourceCommit');
    $migrationHash = thpTxRequireSha256($batch['migrationManifestSha256'] ?? null, 'migrationManifestSha256');
    $baselineRevision = thpTxRequireBoundedString($batch['baselineRevision'] ?? null, 'baselineRevision', 128);
    $committedRevision = thpTxRequireBoundedString($batch['committedRevision'] ?? null, 'committedRevision', 128);
    if ($baselineRevision === $committedRevision) {
        throw new RuntimeException('committedRevision must differ from baselineRevision.');
    }
    $baselineHash = thpTxRequireSha256($batch['baselineStateSha256'] ?? null, 'baselineStateSha256');
    $committedHash = thpTxRequireSha256($batch['expectedCommittedStateSha256'] ?? null, 'expectedCommittedStateSha256');
    if ($baselineHash === $committedHash) {
        throw new RuntimeException('Expected committed state must differ from baseline state.');
    }
    $requiredStages = ['games', 'history', 'points', 'round-state', 'bye-resets', 'attestation'];
    if (thpTxRequireStringList($batch['requiredWriteStages'] ?? null, 'requiredWriteStages') !== $requiredStages) {
        throw new RuntimeException('requiredWriteStages must equal the complete ordered THP write set.');
    }

    $violations = [];
    $success = thpTxRequireProbe($batch['successProbe'] ?? null, false, 'successProbe');
    thpTxAddMismatch($violations, $success['revisionBefore'] !== $baselineRevision, 'success.revision_before');
    thpTxAddMismatch($violations, $success['revisionAfter'] !== $committedRevision, 'success.revision_after');
    thpTxAddMismatch($violations, $success['stateBeforeSha256'] !== $baselineHash, 'success.state_before');
    thpTxAddMismatch($violations, $success['stateAfterSha256'] !== $committedHash, 'success.state_after');
    thpTxAddMismatch($violations, $success['stagesAttempted'] !== $requiredStages, 'success.incomplete_write_set');
    thpTxAddMismatch($violations, $success['committed'] !== true, 'success.not_committed');
    thpTxAddMismatch($violations, $success['rollbackObserved'] !== false, 'success.unexpected_rollback');
    thpTxAddMismatch($violations, $success['commitCount'] !== 1, 'success.commit_count');

    $failureValues = $batch['failureProbes'] ?? null;
    if (!is_array($failureValues) || !array_is_list($failureValues) || count($failureValues) !== count($requiredStages)) {
        throw new RuntimeException('failureProbes must contain exactly one probe for every required write stage.');
    }
    $failureProbes = [];
    foreach ($failureValues as $index => $value) {
        $probe = thpTxRequireProbe($value, true, sprintf('failureProbes[%d]', $index));
        $point = $probe['injectionPoint'] ?? null;
        if (!is_string($point)) {
            throw new RuntimeException(sprintf('failureProbes[%d] lacks a validated injection point.', $index));
        }
        if (isset($failureProbes[$point])) {
            throw new RuntimeException('Duplicate failure injection point: '.$point.'.');
        }
        $failureProbes[$point] = $probe;
    }
    foreach ($requiredStages as $index => $stage) {
        $point = 'after:'.$stage;
        if (!isset($failureProbes[$point])) {
            throw new RuntimeException('Missing failure injection point: '.$point.'.');
        }
        $probe = $failureProbes[$point];
        $prefix = array_slice($requiredStages, 0, $index + 1);
        if ($probe['stagesAttempted'] !== $prefix) {
            throw new RuntimeException($point.' stagesAttempted is not the exact write prefix.');
        }
        if ($probe['jobId'] !== $success['jobId']) {
            throw new RuntimeException($point.' jobId does not match the success probe.');
        }
        thpTxAddMismatch($violations, $probe['revisionBefore'] !== $baselineRevision, $point.'.revision_before');
        thpTxAddMismatch($violations, $probe['revisionAfter'] !== $baselineRevision, $point.'.revision_not_rolled_back');
        thpTxAddMismatch($violations, $probe['stateBeforeSha256'] !== $baselineHash, $point.'.state_before');
        thpTxAddMismatch($violations, $probe['stateAfterSha256'] !== $baselineHash, $point.'.state_not_rolled_back');
        thpTxAddMismatch($violations, $probe['committed'] !== false, $point.'.unexpected_commit');
        thpTxAddMismatch($violations, $probe['rollbackObserved'] !== true, $point.'.rollback_not_observed');
        thpTxAddMismatch($violations, $probe['commitCount'] !== 0, $point.'.commit_count');
    }

    $status = $violations === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.thp-transaction-probe-verification/1',
        'status' => $status,
        'candidateIdentitySha256' => $candidateIdentity,
        'productSourceCommit' => $productCommit,
        'migrationManifestSha256' => $migrationHash,
        'batchSha256' => hash_file('sha256', $batchPath),
        'requiredWriteStages' => $requiredStages,
        'successProbeCount' => 1,
        'failureProbeCount' => count($failureProbes),
        'blockingCodes' => $violations,
        'boundary' => [
            'databaseAccessPerformed' => false,
            'probeHashesIndependentlyDerived' => false,
            'faultInjectionPerformed' => false,
            'databaseMutationPerformed' => false,
            'productionMutationPerformed' => false,
            'submissionGateClosed' => false,
            'note' => 'GO validates a complete identity-bound probe record. The exact product harness must derive state hashes from an isolated database and perform every declared injection.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-transaction-probe-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_transaction_probe.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/** @return array<string, mixed> */
function thpTxDecodeFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Transaction probe batch does not exist.');
    }
    $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    return thpTxRequireObject($decoded, 'batch');
}

/** @return array<string, mixed> */
function thpTxRequireObject(mixed $value, string $label): array
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
function thpTxRequireExactKeys(array $value, array $keys, string $label): void
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
 *   probeId: string,
 *   jobId: string,
 *   revisionBefore: string,
 *   revisionAfter: string,
 *   stateBeforeSha256: string,
 *   stateAfterSha256: string,
 *   stagesAttempted: list<string>,
 *   committed: bool,
 *   rollbackObserved: bool,
 *   commitCount: int,
 *   injectionPoint?: string
 * }
 */
function thpTxRequireProbe(mixed $value, bool $failure, string $label): array
{
    $probe = thpTxRequireObject($value, $label);
    $keys = [
        'probeId', 'jobId', 'revisionBefore', 'revisionAfter', 'stateBeforeSha256', 'stateAfterSha256',
        'stagesAttempted', 'committed', 'rollbackObserved', 'commitCount',
    ];
    if ($failure) {
        $keys[] = 'injectionPoint';
    }
    thpTxRequireExactKeys($probe, $keys, $label);
    $result = [
        'probeId' => thpTxRequirePattern($probe['probeId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.probeId'),
        'jobId' => thpTxRequirePattern($probe['jobId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.jobId'),
        'revisionBefore' => thpTxRequireBoundedString($probe['revisionBefore'] ?? null, $label.'.revisionBefore', 128),
        'revisionAfter' => thpTxRequireBoundedString($probe['revisionAfter'] ?? null, $label.'.revisionAfter', 128),
        'stateBeforeSha256' => thpTxRequireSha256($probe['stateBeforeSha256'] ?? null, $label.'.stateBeforeSha256'),
        'stateAfterSha256' => thpTxRequireSha256($probe['stateAfterSha256'] ?? null, $label.'.stateAfterSha256'),
        'stagesAttempted' => thpTxRequireStringList($probe['stagesAttempted'] ?? null, $label.'.stagesAttempted'),
        'committed' => thpTxRequireBool($probe['committed'] ?? null, $label.'.committed'),
        'rollbackObserved' => thpTxRequireBool($probe['rollbackObserved'] ?? null, $label.'.rollbackObserved'),
        'commitCount' => thpTxRequireCommitCount($probe['commitCount'] ?? null, $label.'.commitCount'),
    ];
    if ($failure) {
        $result['injectionPoint'] = thpTxRequirePattern(
            $probe['injectionPoint'] ?? null,
            '/^after:(games|history|points|round-state|bye-resets|attestation)$/D',
            $label.'.injectionPoint',
        );
    }
    return $result;
}

/** @return list<string> */
function thpTxRequireStringList(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new RuntimeException($label.' must be a list.');
    }
    $seen = [];
    $result = [];
    foreach ($value as $index => $item) {
        $item = thpTxRequirePattern($item, '/^[a-z][a-z0-9.-]{1,63}$/D', sprintf('%s[%d]', $label, $index));
        if (isset($seen[$item])) {
            throw new RuntimeException($label.' contains duplicate stage '.$item.'.');
        }
        $seen[$item] = true;
        $result[] = $item;
    }
    return $result;
}

function thpTxRequireString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function thpTxRequireBoundedString(mixed $value, string $label, int $maxLength): string
{
    $value = thpTxRequireString($value, $label);
    if (strlen($value) > $maxLength) {
        throw new RuntimeException(sprintf('%s exceeds %d bytes.', $label, $maxLength));
    }
    return $value;
}

function thpTxRequirePattern(mixed $value, string $pattern, string $label): string
{
    $value = thpTxRequireString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' has an invalid format.');
    }
    return $value;
}

function thpTxRequireSha256(mixed $value, string $label): string
{
    return thpTxRequirePattern($value, '/^[a-f0-9]{64}$/D', $label);
}

function thpTxRequireBool(mixed $value, string $label): bool
{
    if (!is_bool($value)) {
        throw new RuntimeException($label.' must be boolean.');
    }
    return $value;
}

function thpTxRequireCommitCount(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0 || $value > 1) {
        throw new RuntimeException($label.' must be zero or one.');
    }
    return $value;
}

/** @param list<string> $violations */
function thpTxAddMismatch(array &$violations, bool $condition, string $code): void
{
    if ($condition) {
        $violations[] = $code;
    }
}

function thpTxRejectPrivateKeys(mixed $value): void
{
    if (!is_array($value)) {
        return;
    }
    $forbidden = ['player', 'players', 'playerid', 'name', 'email', 'fideid', 'federationid', 'birthdate', 'phone', 'address'];
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
            throw new RuntimeException('Transaction evidence contains forbidden personal-data key.');
        }
        thpTxRejectPrivateKeys($item);
    }
}
