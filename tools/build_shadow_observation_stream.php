#!/usr/bin/env php
<?php

declare(strict_types=1);

$attemptDirectoryArgument = $argv[1] ?? null;
$eventDirectoryArgument = $argv[2] ?? null;
$outputArgument = $argv[3] ?? null;

try {
    if (!is_string($attemptDirectoryArgument) || !is_string($eventDirectoryArgument) || !is_string($outputArgument)) {
        throw new RuntimeException('Usage: build_shadow_observation_stream.php ATTEMPT_DIRECTORY EVENT_DIRECTORY OUTPUT_NDJSON');
    }
    $attemptDirectory = shadowObservationDirectory($attemptDirectoryArgument, 'Attempt');
    $eventDirectory = shadowObservationDirectory($eventDirectoryArgument, 'Event');
    $outputParent = realpath(dirname($outputArgument));
    if ($outputParent === false || !is_dir($outputParent) || is_link($outputParent) || !is_writable($outputParent)) {
        throw new RuntimeException('Output parent must exist, be writable and not be a symbolic link.');
    }
    $outputName = basename($outputArgument);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.ndjson$/D', $outputName) !== 1) {
        throw new RuntimeException('Output must have one safe .ndjson filename.');
    }
    $output = $outputParent.DIRECTORY_SEPARATOR.$outputName;
    if (file_exists($output) || is_link($output)) {
        throw new RuntimeException('Output already exists and will not be overwritten.');
    }

    $attempts = shadowObservationObjects($attemptDirectory, 'openpairing.shadow-attempt/1', 'Attempt');
    if ($attempts === []) {
        throw new RuntimeException('Attempt directory contains no complete reservations.');
    }
    $events = shadowObservationObjects($eventDirectory, 'openpairing.shadow-turn/1', 'Event');
    $orphanEvents = array_values(array_diff(array_keys($events), array_keys($attempts)));
    if ($orphanEvents !== []) {
        throw new RuntimeException('Outcome exists without a reserved attempt: '.$orphanEvents[0].'.');
    }

    $lines = [];
    $materializedNotObserved = 0;
    foreach ($attempts as $eventId => $attempt) {
        shadowObservationValidateAttempt($attempt, $eventId);
        $event = $events[$eventId] ?? null;
        if ($event === null) {
            ++$materializedNotObserved;
            $event = [
                'schema' => 'openpairing.shadow-turn/1',
                'eventId' => $eventId,
                'observedAtUtc' => $attempt['reservedAtUtc'],
                'identity' => $attempt['identity'],
                'tournament' => $attempt['tournament'],
                'canonicalInputSha256' => $attempt['canonicalInputSha256'],
                'primaryOutputSha256' => null,
                'shadowOutputSha256' => null,
                'primaryElapsedMs' => 0,
                'shadowElapsedMs' => 0,
                'shadowPeakMemoryBytes' => 0,
                'status' => 'not-observed',
                'validatorOutcome' => 'not-run',
                'features' => $attempt['features'],
            ];
        } else {
            foreach (['identity', 'tournament', 'canonicalInputSha256', 'features'] as $field) {
                if (shadowObservationNormalise($event[$field] ?? null) !== shadowObservationNormalise($attempt[$field])) {
                    throw new RuntimeException(sprintf('Outcome %s differs from its reserved attempt: %s.', $eventId, $field));
                }
            }
        }
        $lines[] = json_encode(
            shadowObservationNormalise($event),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    $stream = implode("\n", $lines)."\n";
    $temporary = $outputParent.DIRECTORY_SEPARATOR.'.'.$outputName.'.'.bin2hex(random_bytes(8)).'.tmp';
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Could not create temporary observation stream.');
    }
    try {
        if (fwrite($handle, $stream) !== strlen($stream) || !fflush($handle)) {
            throw new RuntimeException('Could not write observation stream.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Could not sync observation stream.');
        }
    } finally {
        fclose($handle);
    }
    if (!@link($temporary, $output)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish observation stream atomically.');
    }
    @unlink($temporary);

    echo json_encode([
        'schema' => 'openpairing.shadow-observation-stream-build/1',
        'status' => 'PASS',
        'attemptCount' => count($attempts),
        'outcomeCount' => count($events),
        'materializedNotObserved' => $materializedNotObserved,
        'eventsSha256' => hash('sha256', $stream),
        'output' => $output,
        'databaseMutationPerformed' => false,
        'productionMutationPerformed' => false,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.shadow-observation-stream-build/1',
        'status' => 'ERROR',
        'code' => 'shadow_observation_stream.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

function shadowObservationDirectory(string $argument, string $label): string
{
    $directory = realpath($argument);
    if ($directory === false || !is_dir($directory) || is_link($directory)) {
        throw new RuntimeException($label.' directory is missing, invalid or a symbolic link.');
    }
    return $directory;
}

/** @return array<string, array<string, mixed>> */
function shadowObservationObjects(string $directory, string $schema, string $label): array
{
    $entries = scandir($directory, SCANDIR_SORT_ASCENDING);
    if ($entries === false) {
        throw new RuntimeException('Could not enumerate '.$label.' directory.');
    }
    $objects = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.json$/D', $entry) !== 1) {
            throw new RuntimeException($label.' directory contains a partial or unexpected entry: '.$entry.'.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.$entry;
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException($label.' entry is not one readable regular file: '.$entry.'.');
        }
        $object = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($object) || array_is_list($object) || ($object['schema'] ?? null) !== $schema) {
            throw new RuntimeException($label.' entry has an unsupported envelope: '.$entry.'.');
        }
        /** @var array<string, mixed> $object */
        $eventId = $object['eventId'] ?? null;
        if (!is_string($eventId) || $entry !== $eventId.'.json') {
            throw new RuntimeException($label.' filename does not match its opaque event ID: '.$entry.'.');
        }
        $objects[$eventId] = $object;
    }
    return $objects;
}

/** @param array<string, mixed> $attempt */
function shadowObservationValidateAttempt(array $attempt, string $eventId): void
{
    $allowed = ['schema', 'eventId', 'reservedAtUtc', 'identity', 'tournament', 'canonicalInputSha256', 'features'];
    if (array_diff(array_keys($attempt), $allowed) !== [] || array_diff($allowed, array_keys($attempt)) !== []) {
        throw new RuntimeException('Attempt '.$eventId.' has missing or unexpected fields.');
    }
    $timestamp = $attempt['reservedAtUtc'];
    if (!is_string($timestamp) || !str_ends_with($timestamp, 'Z')) {
        throw new RuntimeException('Attempt '.$eventId.' has an invalid UTC reservation timestamp.');
    }
    new DateTimeImmutable($timestamp);
    if (!is_array($attempt['identity']) || array_is_list($attempt['identity'])
        || !is_array($attempt['tournament']) || array_is_list($attempt['tournament'])
        || !is_string($attempt['canonicalInputSha256'])
        || preg_match('/^[a-f0-9]{64}$/D', $attempt['canonicalInputSha256']) !== 1
        || !is_array($attempt['features']) || !array_is_list($attempt['features'])) {
        throw new RuntimeException('Attempt '.$eventId.' is incomplete.');
    }
    shadowObservationRejectPersonalData($attempt, 'attempt '.$eventId);
}

/** @param array<array-key, mixed> $value */
function shadowObservationRejectPersonalData(array $value, string $path): void
{
    $forbidden = ['playername', 'playernames', 'email', 'emails', 'federationid', 'federationids', 'fideid', 'fideids'];
    foreach ($value as $key => $item) {
        $normalized = strtolower(str_replace(['-', '_'], '', (string) $key));
        if (in_array($normalized, $forbidden, true)) {
            throw new RuntimeException('Personal-data key is forbidden in '.$path.': '.$key.'.');
        }
        if (is_array($item)) {
            shadowObservationRejectPersonalData($item, $path.'.'.$key);
        }
    }
}

function shadowObservationNormalise(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $child) {
        $value[$key] = shadowObservationNormalise($child);
    }
    return $value;
}
