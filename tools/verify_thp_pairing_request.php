#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Format\Json\CanonicalJson;

require dirname(__DIR__).'/vendor/autoload.php';

$manifestPath = $argv[1] ?? null;
$snapshotPath = $argv[2] ?? null;

try {
    if (!is_string($manifestPath) || !is_string($snapshotPath)) {
        throw new RuntimeException('Usage: verify_thp_pairing_request.php MANIFEST SNAPSHOT');
    }
    $manifest = thpRequestDecodeObject($manifestPath, 'pairing request manifest');
    thpRequestRequireExactKeys($manifest, [
        'schema', 'jobId', 'tournamentRevision', 'candidateIdentitySha256', 'submittedAtUtc', 'snapshot',
    ], 'manifest');
    if (($manifest['schema'] ?? null) !== 'openpairing.thp-pairing-request/1') {
        throw new RuntimeException('Pairing request manifest has an unsupported schema.');
    }
    thpRequestRequirePattern($manifest['jobId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', 'jobId', 'an opaque 16..128 character identifier');
    thpRequestRequireBoundedString($manifest['tournamentRevision'] ?? null, 'tournamentRevision', 128);
    thpRequestRequireSha256($manifest['candidateIdentitySha256'] ?? null, 'candidateIdentitySha256');
    thpRequestRequireUtc($manifest['submittedAtUtc'] ?? null, 'submittedAtUtc');

    $snapshot = thpRequestRequireObject($manifest['snapshot'] ?? null, 'snapshot');
    thpRequestRequireExactKeys($snapshot, [
        'path', 'sha256', 'schema', 'round', 'pairingSystem', 'initialColour', 'playerCount', 'eligiblePlayerCount',
    ], 'snapshot');
    $declaredPath = thpRequestRequireRelativePath($snapshot['path'] ?? null, 'snapshot.path');
    if (basename($snapshotPath) !== basename($declaredPath)) {
        throw new RuntimeException('snapshot.path does not identify the supplied snapshot file.');
    }
    $expectedHash = thpRequestRequireSha256($snapshot['sha256'] ?? null, 'snapshot.sha256');
    if (!is_file($snapshotPath)) {
        throw new RuntimeException('Snapshot does not exist.');
    }
    $snapshotBytes = file_get_contents($snapshotPath);
    if (!is_string($snapshotBytes)) {
        throw new RuntimeException('Snapshot could not be read.');
    }
    $actualHash = hash('sha256', $snapshotBytes);
    if (!hash_equals($expectedHash, $actualHash)) {
        throw new RuntimeException('Snapshot SHA-256 mismatch.');
    }

    $codec = new CanonicalJson();
    $state = $codec->decode($snapshotBytes);
    if ($codec->encode($state) !== $snapshotBytes) {
        throw new RuntimeException('Snapshot is valid but not in byte-canonical OpenPairing JSON form.');
    }
    $derived = [
        'schema' => $state->schemaVersion,
        'round' => $state->round,
        'pairingSystem' => $state->pairingSystem,
        'initialColour' => $state->topColour->value,
        'playerCount' => count($state->players),
        'eligiblePlayerCount' => count($state->activePlayers()),
    ];
    foreach ($derived as $field => $value) {
        if (($snapshot[$field] ?? null) !== $value) {
            throw new RuntimeException(sprintf('snapshot.%s does not match the canonical input.', $field));
        }
    }
    if (!in_array($state->pairingSystem, ['fide-dutch-2026', 'fide-burstein-2026'], true)) {
        throw new RuntimeException('Snapshot pairing system is outside the declared individual FIDE integration scope.');
    }
    if ($derived['eligiblePlayerCount'] < 1) {
        throw new RuntimeException('Snapshot has no player eligible for the requested round.');
    }

    echo json_encode([
        'schema' => 'openpairing.thp-pairing-request-verification/1',
        'status' => 'PASS',
        'jobId' => $manifest['jobId'],
        'tournamentRevision' => $manifest['tournamentRevision'],
        'candidateIdentitySha256' => $manifest['candidateIdentitySha256'],
        'canonicalInputSha256' => $actualHash,
        'requestManifestSha256' => hash_file('sha256', $manifestPath),
        'snapshot' => $derived,
        'boundary' => [
            'engineCalled' => false,
            'databaseMutationPerformed' => false,
            'publicationAuthorized' => false,
            'note' => 'The current revision and input hash must be compared again immediately before an atomic product commit.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-pairing-request-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_pairing_request.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/** @return array<string, mixed> */
function thpRequestDecodeObject(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    return thpRequestRequireObject($decoded, $label);
}

/** @return array<string, mixed> */
function thpRequestRequireObject(mixed $value, string $label): array
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
function thpRequestRequireExactKeys(array $value, array $keys, string $label): void
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

function thpRequestRequireString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function thpRequestRequireBoundedString(mixed $value, string $label, int $maxLength): string
{
    $value = thpRequestRequireString($value, $label);
    if (strlen($value) > $maxLength) {
        throw new RuntimeException(sprintf('%s exceeds %d bytes.', $label, $maxLength));
    }
    return $value;
}

function thpRequestRequirePattern(mixed $value, string $pattern, string $label, string $description): string
{
    $value = thpRequestRequireString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' must be '.$description.'.');
    }
    return $value;
}

function thpRequestRequireSha256(mixed $value, string $label): string
{
    return thpRequestRequirePattern($value, '/^[a-f0-9]{64}$/D', $label, 'a lowercase SHA-256');
}

function thpRequestRequireUtc(mixed $value, string $label): string
{
    $value = thpRequestRequirePattern($value, '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $label, 'a second-precision UTC timestamp');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone('UTC'));
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\\TH:i:s\\Z') !== $value) {
        throw new RuntimeException($label.' is not a valid UTC timestamp.');
    }
    return $value;
}

function thpRequestRequireRelativePath(mixed $value, string $label): string
{
    $path = thpRequestRequireString($value, $label);
    if (str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/D', $path) === 1) {
        throw new RuntimeException($label.' must be a portable relative path.');
    }
    $segments = explode('/', $path);
    if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        throw new RuntimeException($label.' contains an unsafe path segment.');
    }
    return $path;
}
