#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Diagnostics\Diagnostic;
use OpenPairing\Domain\Pairing\FloatAssignment;
use OpenPairing\Domain\Pairing\FloatDirection;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Pairing\PairingTable;
use OpenPairing\Format\Json\CanonicalJson;
use OpenPairing\Validation\PairingResultValidator;

require dirname(__DIR__).'/vendor/autoload.php';

$requestPath = $argv[1] ?? null;
$snapshotPath = $argv[2] ?? null;
$candidatePath = $argv[3] ?? null;
$artifactRoot = $argv[4] ?? null;
$resultPath = $argv[5] ?? null;

try {
    if (!is_string($requestPath) || !is_string($snapshotPath) || !is_string($candidatePath)
        || !is_string($artifactRoot) || !is_string($resultPath)) {
        throw new RuntimeException('Usage: verify_thp_pairing_result.php REQUEST SNAPSHOT CANDIDATE_IDENTITY ARTIFACT_ROOT RESULT');
    }

    $identityVerification = thpResultRunVerifier([
        PHP_BINARY,
        __DIR__.'/verify_thp_candidate_identity.php',
        $candidatePath,
        $artifactRoot,
    ], 'candidate identity');
    $requestVerification = thpResultRunVerifier([
        PHP_BINARY,
        __DIR__.'/verify_thp_pairing_request.php',
        $requestPath,
        $snapshotPath,
    ], 'pairing request');
    $candidate = thpResultDecodeObject($candidatePath, 'candidate identity manifest');
    $package = thpResultRequireObject($candidate['package'] ?? null, 'candidate.package');
    $expectedCandidate = thpResultRequireSha256($identityVerification['candidateIdentitySha256'] ?? null, 'verified candidateIdentitySha256');
    $requestCandidate = thpResultRequireSha256($requestVerification['candidateIdentitySha256'] ?? null, 'request candidateIdentitySha256');
    if (!hash_equals($expectedCandidate, $requestCandidate)) {
        throw new RuntimeException('Pairing request is not bound to the verified candidate identity.');
    }

    $envelope = thpResultDecodeObject($resultPath, 'pairing result envelope');
    thpResultRequireExactKeys($envelope, [
        'schema', 'jobId', 'tournamentRevision', 'candidateIdentitySha256', 'canonicalInputSha256',
        'completedAtUtc', 'engineVersion', 'engineSha256', 'functionalOutputSha256', 'status', 'result',
    ], 'result envelope');
    if (($envelope['schema'] ?? null) !== 'openpairing.thp-pairing-result/1') {
        throw new RuntimeException('Pairing result envelope has an unsupported schema.');
    }
    foreach (['jobId', 'tournamentRevision'] as $field) {
        $actual = thpResultRequireString($envelope[$field] ?? null, $field);
        $expected = thpResultRequireString($requestVerification[$field] ?? null, 'verified request '.$field);
        if ($actual !== $expected) {
            throw new RuntimeException($field.' does not match the verified request.');
        }
    }
    foreach (['candidateIdentitySha256', 'canonicalInputSha256'] as $field) {
        $actual = thpResultRequireSha256($envelope[$field] ?? null, $field);
        $expected = thpResultRequireSha256($requestVerification[$field] ?? null, 'verified request '.$field);
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException($field.' does not match the verified request.');
        }
    }
    thpResultRequireUtc($envelope['completedAtUtc'] ?? null, 'completedAtUtc');
    if (($envelope['status'] ?? null) !== 'success') {
        throw new RuntimeException('Only a success result may enter the product commit gate.');
    }
    $engineVersion = thpResultRequireString($envelope['engineVersion'] ?? null, 'engineVersion');
    $engineSha256 = thpResultRequireSha256($envelope['engineSha256'] ?? null, 'engineSha256');
    if ($engineVersion !== thpResultRequireString($package['engineVersion'] ?? null, 'candidate.package.engineVersion')) {
        throw new RuntimeException('Pairing result engineVersion does not match the verified candidate.');
    }
    if (!hash_equals(thpResultRequireSha256($package['engineSha256'] ?? null, 'candidate.package.engineSha256'), $engineSha256)) {
        throw new RuntimeException('Pairing result engineSha256 does not match the verified candidate.');
    }

    $rawResult = thpResultRequireObject($envelope['result'] ?? null, 'result');
    thpResultRequireExactKeys($rawResult, [
        'algorithmVersion', 'tables', 'byePlayerId', 'unpairedPlayerIds', 'diagnostics',
        'violations', 'qualityVector', 'trace', 'floatAssignments',
    ], 'result');
    $algorithmVersion = thpResultRequireString($rawResult['algorithmVersion'] ?? null, 'result.algorithmVersion');
    if ($algorithmVersion !== $engineVersion) {
        throw new RuntimeException('result.algorithmVersion does not match the verified candidate engineVersion.');
    }
    $tables = thpResultTables($rawResult['tables'] ?? null);
    $byePlayerId = thpResultNullablePositiveInteger($rawResult['byePlayerId'] ?? null, 'result.byePlayerId');
    $unpaired = thpResultPositiveIntegerList($rawResult['unpairedPlayerIds'] ?? null, 'result.unpairedPlayerIds', true);
    $diagnostics = thpResultDiagnostics($rawResult['diagnostics'] ?? null, 'result.diagnostics');
    $engineViolations = thpResultDiagnostics($rawResult['violations'] ?? null, 'result.violations');
    $qualityVector = thpResultIntegerList($rawResult['qualityVector'] ?? null, 'result.qualityVector');
    $trace = thpResultTrace($rawResult['trace'] ?? null);
    $floats = thpResultFloats($rawResult['floatAssignments'] ?? null);

    $functional = thpResultFunctional($algorithmVersion, $tables, $byePlayerId, $unpaired, $engineViolations, $qualityVector, $floats);
    $actualFunctionalHash = hash('sha256', json_encode($functional, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $declaredFunctionalHash = thpResultRequireSha256($envelope['functionalOutputSha256'] ?? null, 'functionalOutputSha256');
    if (!hash_equals($declaredFunctionalHash, $actualFunctionalHash)) {
        throw new RuntimeException('Functional output SHA-256 mismatch.');
    }

    $snapshotBytes = file_get_contents($snapshotPath);
    if (!is_string($snapshotBytes)) {
        throw new RuntimeException('Snapshot could not be read for product-side validation.');
    }
    $state = (new CanonicalJson())->decode($snapshotBytes);
    $pairingResult = new PairingResult(
        tables: $tables,
        byePlayerId: $byePlayerId,
        unpairedPlayerIds: $unpaired,
        diagnostics: $diagnostics,
        violations: $engineViolations,
        qualityVector: $qualityVector,
        trace: $trace,
        algorithmVersion: $algorithmVersion,
        floatAssignments: $floats,
    );
    $localViolations = (new PairingResultValidator())->validate($state, $pairingResult);
    $blockingCodes = array_values(array_unique(array_merge(
        array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $engineViolations),
        array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $localViolations),
        $unpaired === [] ? [] : ['round.unpaired'],
    )));
    sort($blockingCodes, SORT_STRING);
    $status = $blockingCodes === [] ? 'GO' : 'NO_GO';

    echo json_encode([
        'schema' => 'openpairing.thp-pairing-result-verification/1',
        'status' => $status,
        'jobId' => $envelope['jobId'],
        'tournamentRevision' => $envelope['tournamentRevision'],
        'candidateIdentitySha256' => $expectedCandidate,
        'canonicalInputSha256' => $envelope['canonicalInputSha256'],
        'functionalOutputSha256' => $actualFunctionalHash,
        'counts' => [
            'tables' => count($tables),
            'pairedPlayers' => count($tables) * 2,
            'byePlayers' => $byePlayerId === null ? 0 : 1,
            'unpairedPlayers' => count($unpaired),
            'engineViolations' => count($engineViolations),
            'localViolations' => count($localViolations),
        ],
        'blockingCodes' => $blockingCodes,
        'boundary' => [
            'engineCalled' => false,
            'databaseMutationPerformed' => false,
            'revisionRecheckedBeforeCommit' => false,
            'publicationAuthorized' => false,
            'note' => 'GO proves identity-bound output accounting and local validation only. The product must still re-read revision and canonical input immediately before one atomic commit.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-pairing-result-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_pairing_result.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/**
 * @param list<string> $command
 * @return array<string, mixed>
 */
function thpResultRunVerifier(array $command, string $label): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start '.$label.' verifier.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException($label.' verification failed: '.trim((string) $stderr));
    }
    return thpResultDecodeJsonObject((string) $stdout, $label.' verification report');
}

/** @return array<string, mixed> */
function thpResultDecodeObject(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    $size = filesize($path);
    if (!is_int($size) || $size > 8_388_608) {
        throw new RuntimeException($label.' exceeds the 8 MiB verification limit.');
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException($label.' could not be read.');
    }
    return thpResultDecodeJsonObject($contents, $label);
}

/** @return array<string, mixed> */
function thpResultDecodeJsonObject(string $json, string $label): array
{
    $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    return thpResultRequireObject($decoded, $label);
}

/** @return array<string, mixed> */
function thpResultRequireObject(mixed $value, string $label): array
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
function thpResultRequireExactKeys(array $value, array $keys, string $label): void
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

function thpResultRequireString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function thpResultRequireBoundedString(mixed $value, string $label, int $maximumBytes): string
{
    $value = thpResultRequireString($value, $label);
    if (strlen($value) > $maximumBytes) {
        throw new RuntimeException(sprintf('%s exceeds %d bytes.', $label, $maximumBytes));
    }
    return $value;
}

function thpResultRequireSha256(mixed $value, string $label): string
{
    $value = thpResultRequireString($value, $label);
    if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
        throw new RuntimeException($label.' must be a lowercase SHA-256.');
    }
    return $value;
}

function thpResultRequireUtc(mixed $value, string $label): string
{
    $value = thpResultRequireString($value, $label);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
        throw new RuntimeException($label.' must be a valid second-precision UTC timestamp.');
    }
    return $value;
}

function thpResultNullablePositiveInteger(mixed $value, string $label): ?int
{
    if ($value === null) {
        return null;
    }
    if (!is_int($value) || $value < 1) {
        throw new RuntimeException($label.' must be null or a positive integer.');
    }
    return $value;
}

/** @return list<int> */
function thpResultIntegerList(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 4096) {
        throw new RuntimeException($label.' must be a bounded list.');
    }
    $result = [];
    foreach ($value as $item) {
        if (!is_int($item)) {
            throw new RuntimeException($label.' must contain integers.');
        }
        $result[] = $item;
    }
    return $result;
}

/** @return list<int> */
function thpResultPositiveIntegerList(mixed $value, string $label, bool $unique): array
{
    $result = thpResultIntegerList($value, $label);
    $seen = [];
    foreach ($result as $item) {
        if ($item < 1) {
            throw new RuntimeException($label.' must contain positive integers.');
        }
        if ($unique && isset($seen[$item])) {
            throw new RuntimeException($label.' contains a duplicate player ID.');
        }
        $seen[$item] = true;
    }
    return $result;
}

/** @return list<PairingTable> */
function thpResultTables(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 2048) {
        throw new RuntimeException('result.tables must be a bounded list.');
    }
    $tables = [];
    foreach ($value as $index => $raw) {
        $table = thpResultRequireObject($raw, sprintf('result.tables[%d]', $index));
        thpResultRequireExactKeys($table, ['board', 'whitePlayerId', 'blackPlayerId'], sprintf('result.tables[%d]', $index));
        $board = $table['board'] ?? null;
        $white = $table['whitePlayerId'] ?? null;
        $black = $table['blackPlayerId'] ?? null;
        if (!is_int($board) || $board !== $index + 1 || !is_int($white) || $white < 1 || !is_int($black) || $black < 1) {
            throw new RuntimeException(sprintf('result.tables[%d] must contain its contiguous board number and positive player IDs.', $index));
        }
        $tables[] = new PairingTable($board, $white, $black);
    }
    return $tables;
}

/** @return list<Diagnostic> */
function thpResultDiagnostics(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 4096) {
        throw new RuntimeException($label.' must be a bounded list.');
    }
    $diagnostics = [];
    foreach ($value as $index => $raw) {
        $path = sprintf('%s[%d]', $label, $index);
        $item = thpResultRequireObject($raw, $path);
        thpResultRequireExactKeys($item, ['code', 'message', 'severity', 'context'], $path);
        $context = thpResultDiagnosticContext($item['context'] ?? null, $path.'.context');
        $diagnostics[] = new Diagnostic(
            thpResultRequireBoundedString($item['code'] ?? null, $path.'.code', 128),
            is_string($item['message'] ?? null) && strlen($item['message']) <= 4096
                ? $item['message']
                : throw new RuntimeException($path.'.message must be a string of at most 4096 bytes.'),
            thpResultRequireBoundedString($item['severity'] ?? null, $path.'.severity', 32),
            $context,
        );
    }
    return $diagnostics;
}

/** @return array<string, bool|int|string|null> */
function thpResultDiagnosticContext(mixed $value, string $label): array
{
    if ($value === []) {
        return [];
    }
    $context = thpResultRequireObject($value, $label);
    if (count($context) > 128) {
        throw new RuntimeException($label.' contains too many fields.');
    }
    $result = [];
    foreach ($context as $key => $item) {
        if (!is_bool($item) && !is_int($item) && !is_string($item) && $item !== null) {
            throw new RuntimeException($label.'.'.$key.' has an unsupported value.');
        }
        $result[$key] = $item;
    }
    return $result;
}

/** @return list<array{step: int, code: string, detail: string}> */
function thpResultTrace(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 65_536) {
        throw new RuntimeException('result.trace must be a bounded list.');
    }
    $trace = [];
    foreach ($value as $index => $raw) {
        $path = sprintf('result.trace[%d]', $index);
        $item = thpResultRequireObject($raw, $path);
        thpResultRequireExactKeys($item, ['step', 'code', 'detail'], $path);
        $step = $item['step'] ?? null;
        $detail = $item['detail'] ?? null;
        if (!is_int($step) || $step < 0 || !is_string($detail)) {
            throw new RuntimeException($path.' has invalid step or detail fields.');
        }
        if (strlen($detail) > 4096) {
            throw new RuntimeException($path.'.detail exceeds 4096 bytes.');
        }
        $trace[] = ['step' => $step, 'code' => thpResultRequireBoundedString($item['code'] ?? null, $path.'.code', 128), 'detail' => $detail];
    }
    return $trace;
}

/** @return list<FloatAssignment> */
function thpResultFloats(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 4096) {
        throw new RuntimeException('result.floatAssignments must be a bounded list.');
    }
    $floats = [];
    $seen = [];
    foreach ($value as $index => $raw) {
        $path = sprintf('result.floatAssignments[%d]', $index);
        $item = thpResultRequireObject($raw, $path);
        thpResultRequireExactKeys($item, ['playerId', 'direction'], $path);
        $playerId = $item['playerId'] ?? null;
        $direction = $item['direction'] ?? null;
        if (!is_int($playerId) || $playerId < 1 || !is_string($direction) || FloatDirection::tryFrom($direction) === null) {
            throw new RuntimeException($path.' has invalid playerId or direction fields.');
        }
        if (isset($seen[$playerId])) {
            throw new RuntimeException('result.floatAssignments contains a duplicate player ID.');
        }
        $seen[$playerId] = true;
        $floats[] = new FloatAssignment($playerId, FloatDirection::from($direction));
    }
    return $floats;
}

/**
 * @param list<PairingTable> $tables
 * @param list<int> $unpaired
 * @param list<Diagnostic> $violations
 * @param list<int> $qualityVector
 * @param list<FloatAssignment> $floats
 * @return array<string, mixed>
 */
function thpResultFunctional(string $algorithmVersion, array $tables, ?int $byePlayerId, array $unpaired, array $violations, array $qualityVector, array $floats): array
{
    sort($unpaired, SORT_NUMERIC);
    $floatRecords = array_map(static fn (FloatAssignment $float): array => $float->toArray(), $floats);
    usort($floatRecords, static fn (array $left, array $right): int => $left['playerId'] <=> $right['playerId']);
    return [
        'algorithmVersion' => $algorithmVersion,
        'tables' => array_map(static fn (PairingTable $table): array => $table->toArray(), $tables),
        'byePlayerId' => $byePlayerId,
        'unpairedPlayerIds' => $unpaired,
        'violations' => array_map(static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(), $violations),
        'qualityVector' => $qualityVector,
        'floatAssignments' => $floatRecords,
    ];
}
