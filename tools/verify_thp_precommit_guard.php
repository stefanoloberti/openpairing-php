#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Format\Json\CanonicalJson;

require dirname(__DIR__).'/vendor/autoload.php';

$requestPath = $argv[1] ?? null;
$originalSnapshotPath = $argv[2] ?? null;
$observationPath = $argv[3] ?? null;
$freshSnapshotPath = $argv[4] ?? null;

try {
    if (!is_string($requestPath) || !is_string($originalSnapshotPath) || !is_string($observationPath) || !is_string($freshSnapshotPath)) {
        throw new RuntimeException('Usage: verify_thp_precommit_guard.php REQUEST ORIGINAL_SNAPSHOT OBSERVATION FRESH_SNAPSHOT');
    }

    [$requestExit, $requestStdout, $requestStderr] = thpGuardExecute([
        PHP_BINARY,
        __DIR__.'/verify_thp_pairing_request.php',
        $requestPath,
        $originalSnapshotPath,
    ]);
    if ($requestExit !== 0) {
        throw new RuntimeException('Original pairing request is invalid: '.trim($requestStderr));
    }
    $requestVerification = thpGuardDecodeJson($requestStdout, 'request verification');
    if (($requestVerification['status'] ?? null) !== 'PASS') {
        throw new RuntimeException('Original pairing request did not produce PASS.');
    }
    $request = thpGuardDecodeFile($requestPath, 'pairing request');
    $observation = thpGuardDecodeFile($observationPath, 'pre-commit observation');
    thpGuardRequireExactKeys($observation, [
        'schema', 'jobId', 'candidateIdentitySha256', 'observedAtUtc', 'tournamentRevision', 'snapshot',
    ], 'observation');
    if (($observation['schema'] ?? null) !== 'openpairing.thp-precommit-observation/1') {
        throw new RuntimeException('Pre-commit observation has an unsupported schema.');
    }
    $jobId = thpGuardRequirePattern($observation['jobId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', 'observation.jobId');
    $candidateIdentity = thpGuardRequireSha256($observation['candidateIdentitySha256'] ?? null, 'observation.candidateIdentitySha256');
    $observedAt = thpGuardRequireUtc($observation['observedAtUtc'] ?? null, 'observation.observedAtUtc');
    $revision = thpGuardRequireBoundedString($observation['tournamentRevision'] ?? null, 'observation.tournamentRevision', 128);
    if ($jobId !== ($requestVerification['jobId'] ?? null)) {
        throw new RuntimeException('Observation jobId does not match the original request.');
    }
    if ($candidateIdentity !== ($requestVerification['candidateIdentitySha256'] ?? null)) {
        throw new RuntimeException('Observation candidateIdentitySha256 does not match the original request.');
    }
    $submittedAt = $request['submittedAtUtc'] ?? null;
    if (!is_string($submittedAt) || $observedAt < $submittedAt) {
        throw new RuntimeException('Observation timestamp predates the original request.');
    }

    $snapshot = thpGuardRequireObject($observation['snapshot'] ?? null, 'observation.snapshot');
    thpGuardRequireExactKeys($snapshot, ['path', 'sha256'], 'observation.snapshot');
    $declaredPath = thpGuardRequireRelativePath($snapshot['path'] ?? null, 'observation.snapshot.path');
    $observationRoot = realpath(dirname($observationPath));
    $suppliedSnapshot = realpath($freshSnapshotPath);
    $declaredSnapshot = $observationRoot === false
        ? false
        : realpath($observationRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $declaredPath));
    if ($observationRoot === false || $suppliedSnapshot === false || $declaredSnapshot === false || $suppliedSnapshot !== $declaredSnapshot) {
        throw new RuntimeException('observation.snapshot.path does not identify the supplied fresh snapshot file.');
    }
    $expectedHash = thpGuardRequireSha256($snapshot['sha256'] ?? null, 'observation.snapshot.sha256');
    $freshBytes = file_get_contents($suppliedSnapshot);
    if (!is_string($freshBytes)) {
        throw new RuntimeException('Fresh snapshot could not be read.');
    }
    $freshHash = hash('sha256', $freshBytes);
    if (!hash_equals($expectedHash, $freshHash)) {
        throw new RuntimeException('Fresh snapshot SHA-256 mismatch.');
    }
    $codec = new CanonicalJson();
    $freshState = $codec->decode($freshBytes);
    if ($codec->encode($freshState) !== $freshBytes) {
        throw new RuntimeException('Fresh snapshot is valid but not in byte-canonical OpenPairing JSON form.');
    }

    $blockingCodes = [];
    if ($revision !== ($requestVerification['tournamentRevision'] ?? null)) {
        $blockingCodes[] = 'stale.tournament_revision';
    }
    if ($freshHash !== ($requestVerification['canonicalInputSha256'] ?? null)) {
        $blockingCodes[] = 'stale.canonical_input';
    }
    $status = $blockingCodes === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.thp-precommit-guard-verification/1',
        'status' => $status,
        'jobId' => $jobId,
        'candidateIdentitySha256' => $candidateIdentity,
        'requestManifestSha256' => hash_file('sha256', $requestPath),
        'observationManifestSha256' => hash_file('sha256', $observationPath),
        'originalCanonicalInputSha256' => $requestVerification['canonicalInputSha256'],
        'freshCanonicalInputSha256' => $freshHash,
        'tournamentRevision' => [
            'requested' => $requestVerification['tournamentRevision'],
            'observed' => $revision,
        ],
        'blockingCodes' => $blockingCodes,
        'boundary' => [
            'databaseRereadPerformed' => false,
            'suppliedObservationProvenLive' => false,
            'databaseMutationPerformed' => false,
            'atomicCommitAuthorized' => false,
            'publicationAuthorized' => false,
            'note' => 'GO verifies supplied pre-commit evidence only. The product adapter must create it from a live reread immediately before its atomic transaction.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-precommit-guard-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_precommit_guard.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/**
 * @param list<string> $command
 * @return array{int, string, string}
 */
function thpGuardExecute(array $command): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start pre-commit guard subprocess.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
}

/** @return array<string, mixed> */
function thpGuardDecodeFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    return thpGuardDecodeJson((string) file_get_contents($path), $label);
}

/** @return array<string, mixed> */
function thpGuardDecodeJson(string $json, string $label): array
{
    $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    return thpGuardRequireObject($decoded, $label);
}

/** @return array<string, mixed> */
function thpGuardRequireObject(mixed $value, string $label): array
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
function thpGuardRequireExactKeys(array $value, array $keys, string $label): void
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

function thpGuardRequireString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function thpGuardRequireBoundedString(mixed $value, string $label, int $maxLength): string
{
    $value = thpGuardRequireString($value, $label);
    if (strlen($value) > $maxLength) {
        throw new RuntimeException(sprintf('%s exceeds %d bytes.', $label, $maxLength));
    }
    return $value;
}

function thpGuardRequirePattern(mixed $value, string $pattern, string $label): string
{
    $value = thpGuardRequireString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' has an invalid format.');
    }
    return $value;
}

function thpGuardRequireSha256(mixed $value, string $label): string
{
    return thpGuardRequirePattern($value, '/^[a-f0-9]{64}$/D', $label);
}

function thpGuardRequireUtc(mixed $value, string $label): string
{
    $value = thpGuardRequirePattern($value, '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $label);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone('UTC'));
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\\TH:i:s\\Z') !== $value) {
        throw new RuntimeException($label.' is not a valid UTC timestamp.');
    }
    return $value;
}

function thpGuardRequireRelativePath(mixed $value, string $label): string
{
    $path = thpGuardRequireString($value, $label);
    if (str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/D', $path) === 1) {
        throw new RuntimeException($label.' must be a portable relative path.');
    }
    $segments = explode('/', $path);
    if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        throw new RuntimeException($label.' contains an unsafe path segment.');
    }
    return $path;
}
