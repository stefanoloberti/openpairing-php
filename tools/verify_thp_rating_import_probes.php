#!/usr/bin/env php
<?php

declare(strict_types=1);

$batchPath = $argv[1] ?? null;
$candidatePath = $argv[2] ?? null;
$artifactRoot = $argv[3] ?? null;

try {
    if (!is_string($batchPath) || !is_string($candidatePath) || !is_string($artifactRoot)) {
        throw new RuntimeException('Usage: verify_thp_rating_import_probes.php BATCH.json CANDIDATE.json ARTIFACT_ROOT');
    }
    $identity = ratingRunVerifier([
        PHP_BINARY, __DIR__.'/verify_thp_candidate_identity.php', $candidatePath, $artifactRoot,
    ]);
    $candidateIdentity = ratingSha($identity['candidateIdentitySha256'] ?? null, 'verified candidateIdentitySha256');
    $candidate = ratingDecodeFile($candidatePath, 'candidate identity');
    $product = ratingObject($candidate['product'] ?? null, 'candidate.product');
    $expectedCommit = ratingPattern($product['sourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'candidate.product.sourceCommit');

    $batch = ratingDecodeFile($batchPath, 'rating import probe batch');
    ratingRejectPrivateKeys($batch);
    ratingExactKeys($batch, [
        'schema', 'candidateIdentitySha256', 'productSourceCommit', 'migrationManifestSha256',
        'requiredListPeriod', 'scenarios',
    ], 'batch');
    if (($batch['schema'] ?? null) !== 'openpairing.thp-rating-import-probe-batch/1') {
        throw new RuntimeException('Rating import probe batch has an unsupported schema.');
    }
    if (!hash_equals($candidateIdentity, ratingSha($batch['candidateIdentitySha256'] ?? null, 'candidateIdentitySha256'))) {
        throw new RuntimeException('Rating import probe batch is not bound to the verified candidate identity.');
    }
    if ($expectedCommit !== ratingPattern($batch['productSourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'productSourceCommit')) {
        throw new RuntimeException('Rating import probe batch product source commit differs from the verified candidate.');
    }
    ratingSha($batch['migrationManifestSha256'] ?? null, 'migrationManifestSha256');
    $requiredPeriod = ratingPeriod($batch['requiredListPeriod'] ?? null, 'requiredListPeriod');

    $rawScenarios = $batch['scenarios'] ?? null;
    if (!is_array($rawScenarios) || !array_is_list($rawScenarios) || count($rawScenarios) !== 5) {
        throw new RuntimeException('scenarios must contain exactly the five required rating-list cases.');
    }
    $scenarios = [];
    $ids = [];
    foreach ($rawScenarios as $index => $raw) {
        $scenario = ratingScenario($raw, sprintf('scenarios[%d]', $index));
        if (isset($ids[$scenario['scenarioId']])) {
            throw new RuntimeException('Duplicate rating import scenarioId: '.$scenario['scenarioId'].'.');
        }
        if (isset($scenarios[$scenario['kind']])) {
            throw new RuntimeException('Duplicate rating import scenario kind: '.$scenario['kind'].'.');
        }
        if (!hash_equals($candidateIdentity, $scenario['candidateIdentitySha256'])) {
            throw new RuntimeException('Rating import scenario is not bound to the verified candidate: '.$scenario['scenarioId'].'.');
        }
        $ids[$scenario['scenarioId']] = true;
        $scenarios[$scenario['kind']] = $scenario;
    }
    foreach (['fresh-import', 'identical-reimport', 'malformed-source', 'stale-source', 'injected-db-failure'] as $kind) {
        if (!isset($scenarios[$kind])) {
            throw new RuntimeException('Missing rating import scenario kind: '.$kind.'.');
        }
    }

    $violations = [];
    $fresh = $scenarios['fresh-import'];
    ratingExpect($violations, $fresh, [
        'sourceListPeriod' => $requiredPeriod, 'parserDisposition' => 'accepted', 'rejectedRows' => 0,
        'duplicateFideIds' => 0, 'transactionBeginCount' => 1, 'transactionCommitCount' => 1,
        'transactionRollbackCount' => 0, 'diagnosticCode' => null,
    ]);
    ratingCheckAcceptedReconciliation($violations, $fresh);
    if ($fresh['acceptedRows'] < 1) {
        $violations[] = 'fresh-import.empty_denominator';
    }
    $freshChanges = $fresh['inserted'] + $fresh['updated'] + $fresh['deactivated'];
    if ($freshChanges < 1 || $fresh['persistedWriteCount'] !== $freshChanges || $fresh['writeAttemptCount'] !== $freshChanges) {
        $violations[] = 'fresh-import.write_accounting';
    }
    if (hash_equals($fresh['stateSha256Before'], $fresh['stateSha256After'])) {
        $violations[] = 'fresh-import.state_unchanged';
    }

    $repeat = $scenarios['identical-reimport'];
    ratingExpect($violations, $repeat, [
        'sourceArtifactSha256' => $fresh['sourceArtifactSha256'], 'sourceListPeriod' => $requiredPeriod,
        'parserDisposition' => 'accepted', 'decodedRows' => $fresh['decodedRows'],
        'acceptedRows' => $fresh['acceptedRows'], 'rejectedRows' => 0, 'duplicateFideIds' => 0,
        'existingBefore' => $fresh['finalActive'], 'inserted' => 0, 'updated' => 0,
        'unchanged' => $fresh['acceptedRows'], 'deactivated' => 0, 'finalActive' => $fresh['finalActive'],
        'writeAttemptCount' => 0, 'persistedWriteCount' => 0, 'transactionBeginCount' => 1,
        'transactionCommitCount' => 1, 'transactionRollbackCount' => 0,
        'stateSha256Before' => $fresh['stateSha256After'], 'stateSha256After' => $fresh['stateSha256After'],
        'diagnosticCode' => null,
    ]);

    $malformed = $scenarios['malformed-source'];
    ratingExpectRejected($violations, $malformed, 'rating_list.malformed', $fresh['stateSha256After']);

    $stale = $scenarios['stale-source'];
    ratingExpectRejected($violations, $stale, 'rating_list.stale_period', $fresh['stateSha256After']);
    if ($stale['sourceListPeriod'] >= $requiredPeriod) {
        $violations[] = 'stale-source.period_not_older';
    }

    $failure = $scenarios['injected-db-failure'];
    ratingExpect($violations, $failure, [
        'parserDisposition' => 'accepted', 'rejectedRows' => 0, 'duplicateFideIds' => 0,
        'persistedWriteCount' => 0, 'transactionBeginCount' => 1, 'transactionCommitCount' => 0,
        'transactionRollbackCount' => 1, 'diagnosticCode' => 'rating_list.persistence_failure',
    ]);
    ratingCheckAcceptedReconciliation($violations, $failure);
    if ($failure['acceptedRows'] < 1 || $failure['writeAttemptCount'] < 1) {
        $violations[] = 'injected-db-failure.vacuous';
    }
    if (!hash_equals($failure['stateSha256Before'], $failure['stateSha256After'])) {
        $violations[] = 'injected-db-failure.rollback_state_mismatch';
    }

    $violations = array_values(array_unique($violations));
    sort($violations, SORT_STRING);
    $status = $violations === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.thp-rating-import-probe-verification/1',
        'status' => $status,
        'candidateIdentitySha256' => $candidateIdentity,
        'productSourceCommit' => $expectedCommit,
        'migrationManifestSha256' => $batch['migrationManifestSha256'],
        'requiredListPeriod' => $requiredPeriod,
        'scenarioCount' => 5,
        'freshRowsAccepted' => $fresh['acceptedRows'],
        'blockingCodes' => $violations,
        'batchSha256' => hash_file('sha256', $batchPath),
        'boundary' => [
            'ratingListDownloadedByVerifier' => false,
            'productImportInvokedByVerifier' => false,
            'databaseAccessPerformed' => false,
            'databaseMutationPerformed' => false,
            'productionMutationPerformed' => false,
            'submissionGateClosed' => false,
            'note' => 'GO validates complete supplied product-harness records; it does not execute or attest the ChessPairings import route.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-rating-import-probe-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_rating_import_probe.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/** @param list<string> $command @return array<string, mixed> */
function ratingRunVerifier(array $command): array
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
    return ratingDecodeJson((string) $stdout, 'candidate identity verification');
}

/** @return array<string, mixed> */
function ratingDecodeFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    $size = filesize($path);
    if (!is_int($size) || $size > 8_388_608) {
        throw new RuntimeException($label.' exceeds the 8 MiB verification limit.');
    }
    return ratingDecodeJson((string) file_get_contents($path), $label);
}

/** @return array<string, mixed> */
function ratingDecodeJson(string $json, string $label): array
{
    return ratingObject(json_decode($json, true, 64, JSON_THROW_ON_ERROR), $label);
}

/** @return array<string, mixed> */
function ratingObject(mixed $value, string $label): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException($label.' must be an object.');
    }
    /** @var array<string, mixed> $value */
    return $value;
}

/** @param array<string, mixed> $value @param list<string> $keys */
function ratingExactKeys(array $value, array $keys, string $label): void
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
function ratingScenario(mixed $value, string $label): array
{
    $scenario = ratingObject($value, $label);
    ratingExactKeys($scenario, [
        'scenarioId', 'kind', 'candidateIdentitySha256', 'sourceArtifactSha256', 'sourceListPeriod',
        'parserDisposition', 'decodedRows', 'acceptedRows', 'rejectedRows', 'duplicateFideIds',
        'existingBefore', 'inserted', 'updated', 'unchanged', 'deactivated', 'finalActive',
        'writeAttemptCount', 'persistedWriteCount', 'transactionBeginCount', 'transactionCommitCount',
        'transactionRollbackCount', 'stateSha256Before', 'stateSha256After', 'diagnosticCode',
    ], $label);
    $result = [
        'scenarioId' => ratingPattern($scenario['scenarioId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.scenarioId'),
        'kind' => ratingEnum($scenario['kind'] ?? null, ['fresh-import', 'identical-reimport', 'malformed-source', 'stale-source', 'injected-db-failure'], $label.'.kind'),
        'candidateIdentitySha256' => ratingSha($scenario['candidateIdentitySha256'] ?? null, $label.'.candidateIdentitySha256'),
        'sourceArtifactSha256' => ratingSha($scenario['sourceArtifactSha256'] ?? null, $label.'.sourceArtifactSha256'),
        'sourceListPeriod' => ratingPeriod($scenario['sourceListPeriod'] ?? null, $label.'.sourceListPeriod'),
        'parserDisposition' => ratingEnum($scenario['parserDisposition'] ?? null, ['accepted', 'rejected'], $label.'.parserDisposition'),
    ];
    foreach (['decodedRows', 'acceptedRows', 'rejectedRows', 'duplicateFideIds', 'existingBefore', 'inserted', 'updated', 'unchanged', 'deactivated', 'finalActive', 'writeAttemptCount', 'persistedWriteCount'] as $field) {
        $result[$field] = ratingNonNegative($scenario[$field] ?? null, $label.'.'.$field);
    }
    foreach (['transactionBeginCount', 'transactionCommitCount', 'transactionRollbackCount'] as $field) {
        $result[$field] = ratingZeroOrOne($scenario[$field] ?? null, $label.'.'.$field);
    }
    $result['stateSha256Before'] = ratingSha($scenario['stateSha256Before'] ?? null, $label.'.stateSha256Before');
    $result['stateSha256After'] = ratingSha($scenario['stateSha256After'] ?? null, $label.'.stateSha256After');
    $result['diagnosticCode'] = $scenario['diagnosticCode'] === null ? null : ratingBoundedString($scenario['diagnosticCode'], $label.'.diagnosticCode', 128);
    return $result;
}

/** @param list<string> $violations @param array<string, mixed> $scenario @param array<string, mixed> $expected */
function ratingExpect(array &$violations, array $scenario, array $expected): void
{
    foreach ($expected as $field => $value) {
        if (($scenario[$field] ?? null) !== $value) {
            $violations[] = $scenario['kind'].'.'.$field;
        }
    }
}

/** @param list<string> $violations @param array<string, mixed> $scenario */
function ratingCheckAcceptedReconciliation(array &$violations, array $scenario): void
{
    if ($scenario['decodedRows'] !== $scenario['acceptedRows'] + $scenario['rejectedRows']) {
        $violations[] = $scenario['kind'].'.decode_accounting';
    }
    if ($scenario['acceptedRows'] !== $scenario['inserted'] + $scenario['updated'] + $scenario['unchanged']) {
        $violations[] = $scenario['kind'].'.reconciliation_accounting';
    }
    if ($scenario['deactivated'] > $scenario['existingBefore']) {
        $violations[] = $scenario['kind'].'.deactivation_accounting';
    }
    $expectedFinal = $scenario['existingBefore'] + $scenario['inserted'] - $scenario['deactivated'];
    if ($scenario['kind'] !== 'injected-db-failure' && $scenario['finalActive'] !== $expectedFinal) {
        $violations[] = $scenario['kind'].'.final_active_accounting';
    }
    if ($scenario['kind'] === 'injected-db-failure' && $scenario['finalActive'] !== $scenario['existingBefore']) {
        $violations[] = $scenario['kind'].'.rollback_active_accounting';
    }
}

/** @param list<string> $violations @param array<string, mixed> $scenario */
function ratingExpectRejected(array &$violations, array $scenario, string $diagnostic, string $expectedState): void
{
    ratingExpect($violations, $scenario, [
        'parserDisposition' => 'rejected', 'decodedRows' => 0, 'acceptedRows' => 0, 'rejectedRows' => 0,
        'duplicateFideIds' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0,
        'writeAttemptCount' => 0, 'persistedWriteCount' => 0, 'transactionBeginCount' => 0,
        'transactionCommitCount' => 0, 'transactionRollbackCount' => 0,
        'stateSha256Before' => $expectedState, 'stateSha256After' => $expectedState,
        'diagnosticCode' => $diagnostic,
    ]);
    if ($scenario['finalActive'] !== $scenario['existingBefore']) {
        $violations[] = $scenario['kind'].'.active_state_changed';
    }
}

function ratingString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function ratingBoundedString(mixed $value, string $label, int $limit): string
{
    $value = ratingString($value, $label);
    if (strlen($value) > $limit) {
        throw new RuntimeException($label.' exceeds its byte limit.');
    }
    return $value;
}

function ratingPattern(mixed $value, string $pattern, string $label): string
{
    $value = ratingString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' has an invalid format.');
    }
    return $value;
}

function ratingSha(mixed $value, string $label): string
{
    return ratingPattern($value, '/^[a-f0-9]{64}$/D', $label);
}

function ratingPeriod(mixed $value, string $label): string
{
    return ratingPattern($value, '/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $label);
}

/** @param list<string> $allowed */
function ratingEnum(mixed $value, array $allowed, string $label): string
{
    $value = ratingString($value, $label);
    if (!in_array($value, $allowed, true)) {
        throw new RuntimeException($label.' has an unsupported value.');
    }
    return $value;
}

function ratingNonNegative(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException($label.' must be a non-negative integer.');
    }
    return $value;
}

function ratingZeroOrOne(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0 || $value > 1) {
        throw new RuntimeException($label.' must be zero or one.');
    }
    return $value;
}

function ratingRejectPrivateKeys(mixed $value, string $path = 'batch'): void
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
        ratingRejectPrivateKeys($item, $path.'.'.$keyText);
    }
}
