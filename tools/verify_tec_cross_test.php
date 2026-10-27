#!/usr/bin/env php
<?php

declare(strict_types=1);

const TEC_CROSS_DIRECTIONS = [
    'external-rtg-to-candidate-ptc' => 'external-rtg',
    'candidate-rtg-to-external-ptc' => 'external-ptc',
];
const TEC_CROSS_STATUSES = ['exact', 'discrepancy', 'infrastructure-error'];
const TEC_CROSS_CLASSIFICATIONS = [
    'input-error',
    'candidate-engine-error',
    'interpretation-divergence',
];
const TEC_CROSS_FORBIDDEN_KEYS = [
    'playername', 'playernames', 'email', 'emails', 'federationid',
    'federationids', 'fideid', 'fideids',
];

$manifestPath = $argv[1] ?? null;
$eventsPath = $argv[2] ?? null;
$minimumTournaments = 50_000;
foreach (array_slice($argv, 3) as $argument) {
    if (preg_match('/^--minimum-tournaments=([1-9][0-9]*)$/D', $argument, $matches) !== 1) {
        failTecCrossInfrastructure('Usage: verify_tec_cross_test.php MANIFEST EVENTS_NDJSON [--minimum-tournaments=N]');
    }
    $minimumTournaments = (int) $matches[1];
}

try {
    if (!is_string($manifestPath) || !is_string($eventsPath)) {
        throw new RuntimeException('Manifest and NDJSON event paths are required.');
    }
    $manifest = tecCrossDecodeObjectFile($manifestPath, 'TEC cross-test manifest');
    tecCrossRequireKeys($manifest, [
        'schema', 'direction', 'candidateIdentity', 'counterpart',
        'attemptedTournaments', 'eventsSha256',
    ], 'manifest');
    tecCrossRequireOnlyKeys($manifest, [
        'schema', 'direction', 'candidateIdentity', 'counterpart',
        'attemptedTournaments', 'eventsSha256',
    ], 'manifest');
    if (($manifest['schema'] ?? null) !== 'openpairing.tec-cross-test-batch/1') {
        throw new RuntimeException('TEC cross-test manifest has an unsupported schema.');
    }
    $direction = tecCrossRequireNonEmptyString($manifest['direction'] ?? null, 'direction');
    if (!isset(TEC_CROSS_DIRECTIONS[$direction])) {
        throw new RuntimeException('TEC cross-test manifest has an unsupported direction.');
    }
    $candidateIdentity = tecCrossRequireCandidateIdentity($manifest['candidateIdentity'] ?? null);
    $counterpart = tecCrossRequireObject($manifest['counterpart'] ?? null, 'counterpart');
    tecCrossRequireKeys($counterpart, ['role', 'name', 'version', 'artifactSha256', 'acceptanceReference'], 'counterpart');
    tecCrossRequireOnlyKeys($counterpart, ['role', 'name', 'version', 'artifactSha256', 'acceptanceReference'], 'counterpart');
    $counterpartRole = tecCrossRequireNonEmptyString($counterpart['role'] ?? null, 'counterpart.role');
    if ($counterpartRole !== TEC_CROSS_DIRECTIONS[$direction]) {
        throw new RuntimeException('TEC cross-test counterpart role does not match the direction.');
    }
    foreach (['name', 'version', 'acceptanceReference'] as $field) {
        tecCrossRequireNonEmptyString($counterpart[$field] ?? null, 'counterpart.'.$field);
    }
    tecCrossRequireSha256($counterpart['artifactSha256'] ?? null, 'counterpart.artifactSha256');
    $attempted = tecCrossRequireNonNegativeInt($manifest['attemptedTournaments'] ?? null, 'attemptedTournaments');
    $expectedEventsHash = tecCrossRequireSha256($manifest['eventsSha256'] ?? null, 'eventsSha256');
    if (!is_file($eventsPath)) {
        throw new RuntimeException('TEC cross-test event stream does not exist.');
    }
    $actualEventsHash = (string) hash_file('sha256', $eventsPath);
    if (!hash_equals($expectedEventsHash, $actualEventsHash)) {
        throw new RuntimeException('TEC cross-test event stream SHA-256 does not match the manifest.');
    }

    $counts = array_fill_keys(TEC_CROSS_STATUSES, 0);
    $classifications = array_fill_keys(TEC_CROSS_CLASSIFICATIONS, 0);
    $events = 0;
    $caseIds = [];
    $inputHashes = [];
    $stream = new SplFileObject($eventsPath, 'r');
    while (!$stream->eof()) {
        $line = trim((string) $stream->fgets());
        if ($line === '') {
            continue;
        }
        ++$events;
        $event = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new RuntimeException(sprintf('TEC cross-test event %d is not an object.', $events));
        }
        tecCrossRejectPersonalData($event, sprintf('event %d', $events));
        tecCrossRequireOnlyKeys($event, [
            'schema', 'caseId', 'inputTrfSha256', 'checkerReportSha256',
            'status', 'classification', 'classificationReference',
            'reproductionReference',
        ], sprintf('event %d', $events));
        tecCrossRequireKeys($event, [
            'schema', 'caseId', 'inputTrfSha256', 'checkerReportSha256', 'status',
        ], sprintf('event %d', $events));
        if (($event['schema'] ?? null) !== 'openpairing.tec-cross-test-case/1') {
            throw new RuntimeException(sprintf('TEC cross-test event %d has an unsupported schema.', $events));
        }
        $caseId = tecCrossRequireNonEmptyString($event['caseId'] ?? null, sprintf('event %d caseId', $events));
        if (isset($caseIds[$caseId])) {
            throw new RuntimeException(sprintf('Duplicate TEC cross-test caseId: %s.', $caseId));
        }
        $caseIds[$caseId] = true;
        $inputHash = tecCrossRequireSha256($event['inputTrfSha256'] ?? null, sprintf('event %d inputTrfSha256', $events));
        if (isset($inputHashes[$inputHash])) {
            throw new RuntimeException(sprintf('Duplicate TEC cross-test input TRF hash in event %d.', $events));
        }
        $inputHashes[$inputHash] = true;
        $reportHash = tecCrossNullableSha256($event['checkerReportSha256'] ?? null, sprintf('event %d checkerReportSha256', $events));
        $status = $event['status'] ?? null;
        if (!is_string($status) || !in_array($status, TEC_CROSS_STATUSES, true)) {
            throw new RuntimeException(sprintf('TEC cross-test event %d has an invalid status.', $events));
        }
        ++$counts[$status];
        if ($status === 'infrastructure-error') {
            foreach (['classification', 'classificationReference', 'reproductionReference'] as $field) {
                if (array_key_exists($field, $event) && $event[$field] !== null) {
                    throw new RuntimeException(sprintf('Infrastructure-error TEC cross-test event %d contains %s.', $events, $field));
                }
            }
            continue;
        }
        if ($reportHash === null) {
            throw new RuntimeException(sprintf('Completed TEC cross-test event %d lacks a checker report hash.', $events));
        }
        if ($status === 'exact') {
            foreach (['classification', 'classificationReference', 'reproductionReference'] as $field) {
                if (array_key_exists($field, $event) && $event[$field] !== null) {
                    throw new RuntimeException(sprintf('Exact TEC cross-test event %d contains %s.', $events, $field));
                }
            }
            continue;
        }
        $classification = $event['classification'] ?? null;
        if (!is_string($classification) || !in_array($classification, TEC_CROSS_CLASSIFICATIONS, true)) {
            throw new RuntimeException(sprintf('Discrepant TEC cross-test event %d lacks a valid classification.', $events));
        }
        ++$classifications[$classification];
        foreach (['classificationReference', 'reproductionReference'] as $field) {
            tecCrossRequireNonEmptyString($event[$field] ?? null, sprintf('event %d %s', $events, $field));
        }
    }

    if ($events !== $attempted) {
        throw new RuntimeException(sprintf('TEC cross-test denominator mismatch: manifest=%d events=%d.', $attempted, $events));
    }

    $blockers = [];
    $review = [];
    if ($events < $minimumTournaments) {
        $blockers[] = ['code' => 'insufficient-tournaments', 'observed' => $events, 'required' => $minimumTournaments];
    }
    if ($counts['infrastructure-error'] > 0) {
        $blockers[] = ['code' => 'infrastructure-outcomes', 'count' => $counts['infrastructure-error']];
    }
    if ($counts['discrepancy'] > 10) {
        $blockers[] = ['code' => 'discrepancy-limit-exceeded', 'count' => $counts['discrepancy'], 'maximumForAnalysis' => 10];
    }
    if ($classifications['candidate-engine-error'] > 0) {
        $blockers[] = ['code' => 'candidate-engine-errors', 'count' => $classifications['candidate-engine-error']];
    }
    if ($classifications['input-error'] > 0) {
        $review[] = ['code' => 'input-errors-require-provider-resolution', 'count' => $classifications['input-error']];
    }
    if ($classifications['interpretation-divergence'] > 0) {
        $review[] = ['code' => 'interpretation-divergences-require-tec-clarification', 'count' => $classifications['interpretation-divergence']];
    }

    $status = $blockers !== [] ? 'NO_GO' : ($review !== [] ? 'REVIEW_REQUIRED' : 'PASS_ZERO_DISCREPANCY');
    echo json_encode([
        'schema' => 'openpairing.tec-cross-test-verification/1',
        'status' => $status,
        'direction' => $direction,
        'manifestSha256' => hash_file('sha256', $manifestPath),
        'eventsSha256' => $actualEventsHash,
        'candidateIdentitySha256' => hash('sha256', json_encode($candidateIdentity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'counts' => [
            'attempted' => $events,
            'exact' => $counts['exact'],
            'discrepancies' => $counts['discrepancy'],
            'infrastructureErrors' => $counts['infrastructure-error'],
            'byClassification' => $classifications,
        ],
        'blockers' => $blockers,
        'review' => $review,
        'boundary' => [
            'tecAcceptanceDeclared' => false,
            'note' => 'A mechanical pass does not replace TEC testing, review or acceptance.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'PASS_ZERO_DISCREPANCY' ? 0 : 1);
} catch (Throwable $exception) {
    failTecCrossInfrastructure($exception->getMessage());
}

/** @return array<string, mixed> */
function tecCrossDecodeObjectFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException($label.' is not an object.');
    }

    return $decoded;
}

/** @return array<string, mixed> */
function tecCrossRequireObject(mixed $value, string $label): array
{
    if (!is_array($value)) {
        throw new RuntimeException($label.' must be an object.');
    }

    return $value;
}

/** @return array<string, string> */
function tecCrossRequireCandidateIdentity(mixed $value): array
{
    $identity = tecCrossRequireObject($value, 'candidateIdentity');
    $keys = [
        'productVersion', 'productBuildSha256', 'packageVersion',
        'engineVersion', 'sourceTreeSha256', 'runtimeArchiveSha256',
        'ptcVersion', 'ptcSha256', 'rtgVersion', 'rtgSha256',
    ];
    tecCrossRequireKeys($identity, $keys, 'candidateIdentity');
    tecCrossRequireOnlyKeys($identity, $keys, 'candidateIdentity');
    $normalized = [];
    foreach ($keys as $key) {
        $normalized[$key] = tecCrossRequireNonEmptyString($identity[$key] ?? null, 'candidateIdentity.'.$key);
    }
    foreach (['productBuildSha256', 'sourceTreeSha256', 'runtimeArchiveSha256', 'ptcSha256', 'rtgSha256'] as $key) {
        tecCrossRequireSha256($normalized[$key], 'candidateIdentity.'.$key);
    }

    return $normalized;
}

/** @param array<string, mixed> $value @param list<string> $required */
function tecCrossRequireKeys(array $value, array $required, string $label): void
{
    $missing = array_values(array_diff($required, array_keys($value)));
    if ($missing !== []) {
        throw new RuntimeException(sprintf('%s lacks required fields: %s.', $label, implode(', ', $missing)));
    }
}

function tecCrossRequireNonEmptyString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }

    return $value;
}

function tecCrossRequireSha256(mixed $value, string $label): string
{
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
        throw new RuntimeException($label.' must be a lowercase SHA-256.');
    }

    return $value;
}

function tecCrossNullableSha256(mixed $value, string $label): ?string
{
    return $value === null ? null : tecCrossRequireSha256($value, $label);
}

function tecCrossRequireNonNegativeInt(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException($label.' must be a non-negative integer.');
    }

    return $value;
}

/** @param array<string, mixed> $value @param list<string> $allowed */
function tecCrossRequireOnlyKeys(array $value, array $allowed, string $label): void
{
    $unexpected = array_values(array_diff(array_keys($value), $allowed));
    if ($unexpected !== []) {
        throw new RuntimeException(sprintf('%s contains unexpected fields: %s.', $label, implode(', ', $unexpected)));
    }
}

/** @param array<string, mixed> $value */
function tecCrossRejectPersonalData(array $value, string $path): void
{
    foreach ($value as $key => $item) {
        $normalized = strtolower(str_replace(['-', '_'], '', (string) $key));
        if (in_array($normalized, TEC_CROSS_FORBIDDEN_KEYS, true)) {
            throw new RuntimeException(sprintf('Personal-data key is forbidden in %s: %s.', $path, $key));
        }
        if (is_array($item)) {
            tecCrossRejectPersonalData($item, $path.'.'.$key);
        }
    }
}

function failTecCrossInfrastructure(string $message): never
{
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.tec-cross-test-verification/1',
        'status' => 'ERROR',
        'code' => 'tec_cross_test.infrastructure',
        'message' => $message,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}
