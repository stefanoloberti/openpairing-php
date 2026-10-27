#!/usr/bin/env php
<?php

declare(strict_types=1);

$eventDirectoryArgument = $argv[1] ?? null;
$outputArgument = $argv[2] ?? null;

try {
    if (!is_string($eventDirectoryArgument) || !is_string($outputArgument)) {
        throw new RuntimeException('Usage: build_shadow_event_stream.php EVENT_DIRECTORY OUTPUT_NDJSON');
    }
    $eventDirectory = realpath($eventDirectoryArgument);
    if ($eventDirectory === false || !is_dir($eventDirectory) || is_link($eventDirectory)) {
        throw new RuntimeException('Event directory is missing, invalid or a symbolic link.');
    }
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

    $entries = scandir($eventDirectory, SCANDIR_SORT_ASCENDING);
    if ($entries === false) {
        throw new RuntimeException('Could not enumerate the event directory.');
    }
    $lines = [];
    $seen = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.json$/D', $entry) !== 1) {
            throw new RuntimeException('Event directory contains a partial or unexpected entry: '.$entry.'.');
        }
        $path = $eventDirectory.DIRECTORY_SEPARATOR.$entry;
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Event entry is not one readable regular file: '.$entry.'.');
        }
        $bytes = file_get_contents($path);
        $event = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
        if (!is_array($event) || array_is_list($event) || ($event['schema'] ?? null) !== 'openpairing.shadow-turn/1') {
            throw new RuntimeException('Event entry has an unsupported envelope: '.$entry.'.');
        }
        $eventId = $event['eventId'] ?? null;
        if (!is_string($eventId) || $entry !== $eventId.'.json') {
            throw new RuntimeException('Event filename does not match its opaque event ID: '.$entry.'.');
        }
        if (isset($seen[$eventId])) {
            throw new RuntimeException('Duplicate event ID: '.$eventId.'.');
        }
        $seen[$eventId] = true;
        $lines[] = json_encode(
            shadowStreamNormalise($event),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
    if ($lines === []) {
        throw new RuntimeException('Event directory contains no complete events.');
    }
    $stream = implode("\n", $lines)."\n";
    $temporary = $outputParent.DIRECTORY_SEPARATOR.'.'.basename($output).'.'.bin2hex(random_bytes(8)).'.tmp';
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Could not create temporary event stream.');
    }
    try {
        if (fwrite($handle, $stream) !== strlen($stream) || !fflush($handle)) {
            throw new RuntimeException('Could not write event stream.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Could not sync event stream.');
        }
    } finally {
        fclose($handle);
    }
    if (!@link($temporary, $output)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish event stream atomically.');
    }
    @unlink($temporary);

    echo json_encode([
        'schema' => 'openpairing.shadow-event-stream-build/1',
        'status' => 'PASS',
        'eventCount' => count($lines),
        'eventsSha256' => hash('sha256', $stream),
        'output' => $output,
        'sourceModified' => false,
        'databaseMutationPerformed' => false,
        'productionMutationPerformed' => false,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.shadow-event-stream-build/1',
        'status' => 'ERROR',
        'code' => 'shadow_event_stream.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

function shadowStreamNormalise(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $child) {
        $value[$key] = shadowStreamNormalise($child);
    }
    return $value;
}
