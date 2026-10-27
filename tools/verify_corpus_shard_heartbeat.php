#!/usr/bin/env php
<?php

declare(strict_types=1);

try {
    if (count($argv) < 9 || count($argv) > 10) {
        throw new RuntimeException(
            'Usage: verify_corpus_shard_heartbeat.php CURRENT CAMPAIGN SHARD ENGINE_SHA256 SOURCE_SHA256 HARNESS_SHA256 LOCK_SHA256 BACKEND [PREVIOUS]'
        );
    }

    [$script, $currentPath, $expectedCampaign, $expectedShard, $expectedEngine,
        $expectedSource, $expectedHarness, $expectedLock, $expectedBackend] = $argv;
    unset($script);
    $previousPath = $argv[9] ?? null;

    foreach ([$expectedEngine, $expectedSource, $expectedHarness, $expectedLock] as $expectedHash) {
        heartbeatRequireSha256($expectedHash, 'expected identity hash');
    }
    if (!in_array($expectedBackend, ['gmp', 'limbs'], true)) {
        throw new RuntimeException('Expected arithmetic backend must be gmp or limbs.');
    }

    $current = heartbeatValidate(
        heartbeatDecode($currentPath, 'current heartbeat'),
        $expectedCampaign,
        $expectedShard,
        $expectedEngine,
        $expectedSource,
        $expectedHarness,
        $expectedLock,
        $expectedBackend,
    );

    $previousCompared = false;
    if (is_string($previousPath)) {
        $previous = heartbeatValidate(
            heartbeatDecode($previousPath, 'previous heartbeat'),
            $expectedCampaign,
            $expectedShard,
            $expectedEngine,
            $expectedSource,
            $expectedHarness,
            $expectedLock,
            $expectedBackend,
        );
        heartbeatRequireMonotonic($previous, $current);
        $previousCompared = true;
    }

    $snapshotSha256 = hash_file('sha256', $currentPath);
    if (!is_string($snapshotSha256)) {
        throw new RuntimeException('Current heartbeat could not be hashed.');
    }

    echo json_encode([
        'schema' => 'openpairing.corpus-shard-heartbeat-verification/1',
        'status' => 'PASS',
        'campaign' => $current['campaign'],
        'shard' => $current['shard'],
        'processStartToken' => $current['processStartToken'],
        'snapshotSha256' => $snapshotSha256,
        'previousCompared' => $previousCompared,
        'observedAtUtc' => $current['observedAtUtc'],
        'elapsedSeconds' => $current['elapsedSeconds'],
        'filesTotal' => $current['filesTotal'],
        'filesCompleted' => $current['filesCompleted'],
        'tournamentsVerified' => $current['tournamentsVerified'],
        'roundsAttempted' => $current['roundsAttempted'],
        'identity' => $current['identity'],
        'boundary' => [
            'terminalReport' => false,
            'acceptedEvidence' => false,
            'campaignMutated' => false,
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.corpus-shard-heartbeat-verification/1',
        'status' => 'ERROR',
        'code' => 'corpus_heartbeat.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/** @return array<string, mixed> */
function heartbeatDecode(string $path, string $label): array
{
    if ($path === '' || is_link($path) || !is_file($path)) {
        throw new RuntimeException($label.' must be one regular non-symlink file.');
    }
    $size = filesize($path);
    if (!is_int($size) || $size < 2 || $size > 1_048_576) {
        throw new RuntimeException($label.' has an invalid byte size.');
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException($label.' could not be read.');
    }
    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException($label.' must contain one JSON object.');
    }

    $object = [];
    foreach ($decoded as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException($label.' contains a non-string object key.');
        }
        $object[$key] = $value;
    }

    return $object;
}

/**
 * @param array<string, mixed> $heartbeat
 * @return array<string, mixed>
 */
function heartbeatValidate(
    array $heartbeat,
    string $expectedCampaign,
    string $expectedShard,
    string $expectedEngine,
    string $expectedSource,
    string $expectedHarness,
    string $expectedLock,
    string $expectedBackend,
): array {
    heartbeatRequireExactFields($heartbeat, [
        'schema', 'campaign', 'shard', 'processStartToken', 'startedAtUtc',
        'observedAtUtc', 'elapsedSeconds', 'filesTotal', 'filesCompleted',
        'tournamentsVerified', 'roundsAttempted', 'rounds', 'identity',
        'lastCompletedInputPathSha256', 'terminal', 'acceptedEvidence',
    ], 'heartbeat');
    if (($heartbeat['schema'] ?? null) !== 'openpairing.corpus-shard-heartbeat/1') {
        throw new RuntimeException('Heartbeat schema is not supported.');
    }
    if (($heartbeat['campaign'] ?? null) !== $expectedCampaign
        || preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $expectedCampaign) !== 1) {
        throw new RuntimeException('Heartbeat campaign identity mismatch.');
    }
    if (($heartbeat['shard'] ?? null) !== $expectedShard
        || preg_match('/^s[0-9]{3}$/D', $expectedShard) !== 1) {
        throw new RuntimeException('Heartbeat shard identity mismatch.');
    }
    heartbeatRequireSha256($heartbeat['processStartToken'] ?? null, 'process-start token');
    $started = heartbeatTimestamp($heartbeat['startedAtUtc'] ?? null, 'startedAtUtc');
    $observed = heartbeatTimestamp($heartbeat['observedAtUtc'] ?? null, 'observedAtUtc');
    if ($observed < $started) {
        throw new RuntimeException('Heartbeat observation precedes worker start.');
    }
    $elapsed = $heartbeat['elapsedSeconds'] ?? null;
    if ((!is_int($elapsed) && !is_float($elapsed)) || !is_finite((float) $elapsed) || $elapsed < 0) {
        throw new RuntimeException('Heartbeat elapsedSeconds must be finite and non-negative.');
    }

    $filesTotal = heartbeatNonNegativeInteger($heartbeat['filesTotal'] ?? null, 'filesTotal');
    $filesCompleted = heartbeatNonNegativeInteger($heartbeat['filesCompleted'] ?? null, 'filesCompleted');
    $tournaments = heartbeatNonNegativeInteger($heartbeat['tournamentsVerified'] ?? null, 'tournamentsVerified');
    $roundsAttempted = heartbeatNonNegativeInteger($heartbeat['roundsAttempted'] ?? null, 'roundsAttempted');
    if ($filesTotal < 1 || $filesCompleted > $filesTotal || $tournaments > $filesCompleted) {
        throw new RuntimeException('Heartbeat file/tournament counters are inconsistent.');
    }

    $rounds = heartbeatObject($heartbeat['rounds'] ?? null, 'Heartbeat rounds');
    heartbeatRequireExactFields($rounds, [
        'exact', 'colourVariant', 'pairingDeviation', 'noSolution', 'resourceLimit',
    ], 'round counters');
    $roundSum = 0;
    foreach ($rounds as $name => $value) {
        $roundSum += heartbeatNonNegativeInteger($value, 'rounds.'.$name);
    }
    if ($roundSum !== $roundsAttempted) {
        throw new RuntimeException('Heartbeat round categories do not equal roundsAttempted.');
    }

    $identity = heartbeatObject($heartbeat['identity'] ?? null, 'Heartbeat identity');
    heartbeatRequireExactFields($identity, [
        'engineSha256', 'sourceTreeSha256', 'harnessSha256',
        'runtimeIdentityLockSha256', 'arithmeticBackend', 'gmpAvailable',
        'cliJitEnabled',
    ], 'heartbeat identity');
    $expectedIdentity = [
        'engineSha256' => $expectedEngine,
        'sourceTreeSha256' => $expectedSource,
        'harnessSha256' => $expectedHarness,
        'runtimeIdentityLockSha256' => $expectedLock,
        'arithmeticBackend' => $expectedBackend,
    ];
    foreach ($expectedIdentity as $field => $expected) {
        if (($identity[$field] ?? null) !== $expected) {
            throw new RuntimeException('Heartbeat identity mismatch for '.$field.'.');
        }
    }
    foreach (['engineSha256', 'sourceTreeSha256', 'harnessSha256', 'runtimeIdentityLockSha256'] as $field) {
        heartbeatRequireSha256($identity[$field] ?? null, 'identity.'.$field);
    }
    if (!is_bool($identity['gmpAvailable'] ?? null) || !is_bool($identity['cliJitEnabled'] ?? null)) {
        throw new RuntimeException('Heartbeat runtime capability fields must be boolean.');
    }
    if ($expectedBackend === 'gmp' && $identity['gmpAvailable'] !== true) {
        throw new RuntimeException('GMP backend heartbeat must attest the GMP extension.');
    }

    $lastInputHash = $heartbeat['lastCompletedInputPathSha256'] ?? null;
    if ($filesCompleted === 0) {
        if ($lastInputHash !== null) {
            throw new RuntimeException('Heartbeat with zero completed files cannot name a last input hash.');
        }
    } else {
        heartbeatRequireSha256($lastInputHash, 'last completed input path hash');
    }
    if (($heartbeat['terminal'] ?? null) !== false || ($heartbeat['acceptedEvidence'] ?? null) !== false) {
        throw new RuntimeException('Heartbeat must remain non-terminal and non-evidentiary.');
    }

    return $heartbeat;
}

/**
 * @param array<string, mixed> $previous
 * @param array<string, mixed> $current
 */
function heartbeatRequireMonotonic(array $previous, array $current): void
{
    foreach (['campaign', 'shard', 'processStartToken', 'startedAtUtc', 'filesTotal'] as $field) {
        if ($previous[$field] !== $current[$field]) {
            throw new RuntimeException('Heartbeat continuity mismatch for '.$field.'.');
        }
    }
    $previousIdentity = heartbeatObject($previous['identity'] ?? null, 'Previous heartbeat identity');
    $currentIdentity = heartbeatObject($current['identity'] ?? null, 'Current heartbeat identity');
    ksort($previousIdentity, SORT_STRING);
    ksort($currentIdentity, SORT_STRING);
    if ($previousIdentity !== $currentIdentity) {
        throw new RuntimeException('Heartbeat continuity mismatch for identity.');
    }
    if (heartbeatTimestamp($current['observedAtUtc'], 'observedAtUtc')
        <= heartbeatTimestamp($previous['observedAtUtc'], 'previous observedAtUtc')) {
        throw new RuntimeException('Heartbeat observation time did not increase.');
    }
    foreach (['elapsedSeconds', 'filesCompleted', 'tournamentsVerified', 'roundsAttempted'] as $field) {
        if ($current[$field] < $previous[$field]) {
            throw new RuntimeException('Heartbeat counter decreased: '.$field.'.');
        }
    }
    $previousRounds = heartbeatObject($previous['rounds'] ?? null, 'Previous heartbeat rounds');
    $currentRounds = heartbeatObject($current['rounds'] ?? null, 'Current heartbeat rounds');
    foreach ($previousRounds as $field => $value) {
        if (!is_int($value) || !isset($currentRounds[$field]) || !is_int($currentRounds[$field])) {
            throw new RuntimeException('Heartbeat round continuity has invalid counter types.');
        }
        if ($currentRounds[$field] < $value) {
            throw new RuntimeException('Heartbeat round counter decreased: '.$field.'.');
        }
    }
}

/**
 * @param array<string, mixed> $object
 * @param list<string> $expected
 */
function heartbeatRequireExactFields(array $object, array $expected, string $label): void
{
    $actual = array_keys($object);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($actual !== $expected) {
        throw new RuntimeException($label.' has missing or unexpected fields.');
    }
}

/** @return array<string, mixed> */
function heartbeatObject(mixed $value, string $label): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException($label.' must be an object.');
    }
    $object = [];
    foreach ($value as $key => $entry) {
        if (!is_string($key)) {
            throw new RuntimeException($label.' contains a non-string object key.');
        }
        $object[$key] = $entry;
    }

    return $object;
}

function heartbeatRequireSha256(mixed $value, string $label): void
{
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
        throw new RuntimeException($label.' must be one lowercase SHA-256.');
    }
}

function heartbeatTimestamp(mixed $value, string $label): int
{
    if (!is_string($value)
        || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) !== 1) {
        throw new RuntimeException($label.' must be a whole-second UTC timestamp.');
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed instanceof DateTimeImmutable
        || (is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
        throw new RuntimeException($label.' is not a valid UTC timestamp.');
    }

    return $parsed->getTimestamp();
}

function heartbeatNonNegativeInteger(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException($label.' must be a non-negative integer.');
    }

    return $value;
}
