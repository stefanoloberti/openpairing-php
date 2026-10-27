#!/usr/bin/env php
<?php

declare(strict_types=1);

const SHADOW_STATUSES = [
    'exact',
    'same-pairs-colour-variant',
    'legal-variant',
    'inferior',
    'local-violation',
    'no-solution',
    'resource-limit',
    'invalid-input',
    'infrastructure-error',
    'not-observed',
];
const SHADOW_OBSERVED_STATUSES = [
    'exact',
    'same-pairs-colour-variant',
    'legal-variant',
    'inferior',
    'local-violation',
    'no-solution',
    'resource-limit',
];
const SHADOW_VALID_OUTPUT_STATUSES = [
    'exact',
    'same-pairs-colour-variant',
    'legal-variant',
    'inferior',
];
const SHADOW_NON_EXACT_OBSERVED_STATUSES = [
    'same-pairs-colour-variant',
    'legal-variant',
    'inferior',
    'local-violation',
    'no-solution',
    'resource-limit',
];
const SHADOW_BLOCKING_STATUSES = [
    'inferior',
    'local-violation',
    'no-solution',
    'resource-limit',
];
const SHADOW_INFRASTRUCTURE_STATUSES = ['infrastructure-error', 'not-observed'];
const SHADOW_REQUIRED_COVERAGE = [
    'initial-white',
    'initial-black',
    'odd-field',
    'even-field',
    'withdrawal',
    'pab',
    'forfeit',
    'scheduled-bye',
    'accelerated',
];
const SHADOW_FORBIDDEN_KEYS = [
    'playername',
    'playernames',
    'email',
    'emails',
    'federationid',
    'federationids',
    'fideid',
    'fideids',
];

$manifestPath = $argv[1] ?? null;
$eventsPath = $argv[2] ?? null;
$minimumTurns = 10_000;
foreach (array_slice($argv, 3) as $argument) {
    if (preg_match('/^--minimum-turns=([1-9][0-9]*)$/', $argument, $matches) !== 1) {
        failInfrastructure('Usage: verify_shadow_evidence.php MANIFEST EVENTS_NDJSON [--minimum-turns=N]');
    }
    $minimumTurns = (int) $matches[1];
}

try {
    if (!is_string($manifestPath) || !is_string($eventsPath)) {
        throw new RuntimeException('Manifest and NDJSON event paths are required.');
    }
    $manifest = decodeObjectFile($manifestPath, 'shadow evidence manifest');
    if (($manifest['schema'] ?? null) !== 'openpairing.shadow-evidence-batch/1') {
        throw new RuntimeException('Shadow evidence manifest has an unsupported schema.');
    }
    $manifestKeys = ['schema', 'identity', 'attemptedTurns', 'eventsSha256', 'budgets', 'fixtureEvidence'];
    requireKeys($manifest, $manifestKeys, 'manifest');
    requireOnlyKeys($manifest, $manifestKeys, 'manifest');
    $identity = requireIdentity($manifest['identity'] ?? null, 'manifest');
    $attemptedTurns = requireNonNegativeInt($manifest['attemptedTurns'] ?? null, 'attemptedTurns');
    $expectedEventsHash = requireSha256($manifest['eventsSha256'] ?? null, 'eventsSha256');
    if (!is_file($eventsPath)) {
        throw new RuntimeException('Shadow event stream does not exist.');
    }
    $actualEventsHash = (string) hash_file('sha256', $eventsPath);
    if (!hash_equals($expectedEventsHash, $actualEventsHash)) {
        throw new RuntimeException('Shadow event stream SHA-256 does not match the manifest.');
    }

    $budgets = requireObject($manifest['budgets'] ?? null, 'budgets');
    requireOnlyKeys($budgets, ['maximumShadowElapsedMs', 'maximumShadowPeakMemoryBytes'], 'budgets');
    $maximumElapsedMs = requirePositiveNumber($budgets['maximumShadowElapsedMs'] ?? null, 'maximumShadowElapsedMs');
    $maximumMemoryBytes = requirePositiveInt($budgets['maximumShadowPeakMemoryBytes'] ?? null, 'maximumShadowPeakMemoryBytes');
    $coverage = fixtureCoverage($manifest['fixtureEvidence'] ?? [], dirname(realpath($manifestPath) ?: $manifestPath));

    $counts = array_fill_keys(SHADOW_STATUSES, 0);
    $events = 0;
    $observed = 0;
    $unclassified = 0;
    $latencyExceeded = 0;
    $memoryExceeded = 0;
    $eventIds = [];
    $stream = new SplFileObject($eventsPath, 'r');
    while (!$stream->eof()) {
        $line = trim((string) $stream->fgets());
        if ($line === '') {
            continue;
        }
        ++$events;
        $event = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($event) || array_is_list($event)) {
            throw new RuntimeException(sprintf('Shadow event %d is not an object.', $events));
        }
        /** @var array<string, mixed> $event */
        rejectPersonalData($event, sprintf('event %d', $events));
        if (($event['schema'] ?? null) !== 'openpairing.shadow-turn/1') {
            throw new RuntimeException(sprintf('Shadow event %d has an unsupported schema.', $events));
        }
        $requiredEventKeys = [
            'schema',
            'eventId',
            'observedAtUtc',
            'identity',
            'tournament',
            'canonicalInputSha256',
            'primaryOutputSha256',
            'shadowOutputSha256',
            'primaryElapsedMs',
            'shadowElapsedMs',
            'shadowPeakMemoryBytes',
            'status',
            'validatorOutcome',
            'features',
        ];
        requireKeys($event, $requiredEventKeys, sprintf('event %d', $events));
        requireOnlyKeys($event, [
            ...$requiredEventKeys,
            'classificationReference',
            'reproductionReference',
        ], sprintf('event %d', $events));
        $eventId = requireNonEmptyString($event['eventId'] ?? null, sprintf('event %d eventId', $events));
        if (isset($eventIds[$eventId])) {
            throw new RuntimeException(sprintf('Duplicate shadow eventId: %s.', $eventId));
        }
        $eventIds[$eventId] = true;
        requireUtcTimestamp($event['observedAtUtc'] ?? null, sprintf('event %d observedAtUtc', $events));
        if (requireIdentity($event['identity'] ?? null, sprintf('event %d', $events)) !== $identity) {
            throw new RuntimeException(sprintf('Shadow event %d identity differs from the batch identity.', $events));
        }

        $tournament = requireObject($event['tournament'] ?? null, sprintf('event %d tournament', $events));
        requireOnlyKeys($tournament, ['mode', 'pairingSystem', 'round', 'playerCount', 'initialColour'], sprintf('event %d tournament', $events));
        requireNonEmptyString($tournament['mode'] ?? null, sprintf('event %d tournament.mode', $events));
        requireNonEmptyString($tournament['pairingSystem'] ?? null, sprintf('event %d tournament.pairingSystem', $events));
        requirePositiveInt($tournament['round'] ?? null, sprintf('event %d tournament.round', $events));
        requirePositiveInt($tournament['playerCount'] ?? null, sprintf('event %d tournament.playerCount', $events));
        $initialColour = $tournament['initialColour'] ?? null;
        if (!in_array($initialColour, ['white', 'black'], true)) {
            throw new RuntimeException(sprintf('Shadow event %d has an invalid initial colour.', $events));
        }
        requireSha256($event['canonicalInputSha256'] ?? null, sprintf('event %d canonicalInputSha256', $events));
        $primaryHash = nullableSha256($event['primaryOutputSha256'], sprintf('event %d primaryOutputSha256', $events));
        requireNonNegativeNumber($event['primaryElapsedMs'] ?? null, sprintf('event %d primaryElapsedMs', $events));
        $shadowElapsedMs = requireNonNegativeNumber($event['shadowElapsedMs'] ?? null, sprintf('event %d shadowElapsedMs', $events));
        $shadowPeakMemoryBytes = requireNonNegativeInt($event['shadowPeakMemoryBytes'] ?? null, sprintf('event %d shadowPeakMemoryBytes', $events));
        $status = $event['status'] ?? null;
        if (!is_string($status) || !in_array($status, SHADOW_STATUSES, true)) {
            throw new RuntimeException(sprintf('Shadow event %d has an invalid status.', $events));
        }
        ++$counts[$status];
        $validatorOutcome = $event['validatorOutcome'] ?? null;
        if (!in_array($validatorOutcome, ['pass', 'fail', 'not-run'], true)) {
            throw new RuntimeException(sprintf('Shadow event %d has an invalid validator outcome.', $events));
        }
        $features = requireStringList($event['features'] ?? [], sprintf('event %d features', $events));
        if (count($features) !== count(array_unique($features))) {
            throw new RuntimeException(sprintf('Shadow event %d has duplicate features.', $events));
        }
        foreach ($features as $feature) {
            if (!in_array($feature, SHADOW_REQUIRED_COVERAGE, true)
                || in_array($feature, ['initial-white', 'initial-black'], true)) {
                throw new RuntimeException(sprintf('Shadow event %d has an unsupported feature: %s.', $events, $feature));
            }
        }
        if (in_array($status, SHADOW_OBSERVED_STATUSES, true)) {
            ++$observed;
            $coverage['initial-'.$initialColour] = true;
            foreach ($features as $feature) {
                $coverage[$feature] = true;
            }
            if ($primaryHash === null) {
                throw new RuntimeException(sprintf('Observed shadow event %d lacks a primary functional hash.', $events));
            }
            $shadowHash = nullableSha256($event['shadowOutputSha256'], sprintf('event %d shadowOutputSha256', $events));
            if (in_array($status, SHADOW_VALID_OUTPUT_STATUSES, true)) {
                if ($shadowHash === null) {
                    throw new RuntimeException(sprintf('Shadow event %d lacks a functional output hash.', $events));
                }
                if ($validatorOutcome !== 'pass') {
                    throw new RuntimeException(sprintf('Valid-output shadow event %d lacks a passing local validator.', $events));
                }
            } elseif ($status === 'local-violation') {
                if ($shadowHash === null || $validatorOutcome !== 'fail') {
                    throw new RuntimeException(sprintf('Local-violation shadow event %d must carry an output hash and a failing validator.', $events));
                }
            }
            if ($status === 'exact' && !hash_equals($primaryHash, $shadowHash)) {
                throw new RuntimeException(sprintf('Exact shadow event %d has different functional hashes.', $events));
            }
            if (in_array($status, ['same-pairs-colour-variant', 'legal-variant', 'inferior'], true)
                && hash_equals($primaryHash, $shadowHash)) {
                throw new RuntimeException(sprintf('Non-exact shadow event %d has identical functional hashes.', $events));
            }
            if (in_array($status, SHADOW_NON_EXACT_OBSERVED_STATUSES, true)
                && (!is_string($event['classificationReference'] ?? null)
                    || trim($event['classificationReference']) === ''
                    || !is_string($event['reproductionReference'] ?? null)
                    || trim($event['reproductionReference']) === '')) {
                ++$unclassified;
            }
        }
        if ($shadowElapsedMs > $maximumElapsedMs) {
            ++$latencyExceeded;
        }
        if ($shadowPeakMemoryBytes > $maximumMemoryBytes) {
            ++$memoryExceeded;
        }
    }

    if ($events !== $attemptedTurns) {
        throw new RuntimeException(sprintf('Attempt denominator mismatch: manifest=%d events=%d.', $attemptedTurns, $events));
    }
    $blockingOutcomes = array_sum(array_intersect_key($counts, array_flip(SHADOW_BLOCKING_STATUSES)));
    $infrastructureOutcomes = array_sum(array_intersect_key($counts, array_flip(SHADOW_INFRASTRUCTURE_STATUSES)));
    $missingCoverage = array_values(array_filter(
        SHADOW_REQUIRED_COVERAGE,
        static fn (string $feature): bool => !isset($coverage[$feature]),
    ));
    $blockers = [];
    if ($observed < $minimumTurns) {
        $blockers[] = ['code' => 'insufficient-observed-turns', 'observed' => $observed, 'required' => $minimumTurns];
    }
    if ($blockingOutcomes > 0) {
        $blockers[] = ['code' => 'blocking-outcomes', 'count' => $blockingOutcomes];
    }
    if ($infrastructureOutcomes > 0) {
        $blockers[] = ['code' => 'unobserved-or-infrastructure', 'count' => $infrastructureOutcomes];
    }
    if ($unclassified > 0) {
        $blockers[] = ['code' => 'unclassified-non-exact', 'count' => $unclassified];
    }
    if ($latencyExceeded > 0 || $memoryExceeded > 0) {
        $blockers[] = ['code' => 'product-budget-exceeded', 'latency' => $latencyExceeded, 'memory' => $memoryExceeded];
    }
    if ($missingCoverage !== []) {
        $blockers[] = ['code' => 'missing-coverage', 'features' => $missingCoverage];
    }

    $status = $blockers === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.shadow-evidence-verification/1',
        'status' => $status,
        'manifestSha256' => hash_file('sha256', $manifestPath),
        'eventsSha256' => $actualEventsHash,
        'identitySha256' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'counts' => [
            'attempted' => $attemptedTurns,
            'observed' => $observed,
            'invalidInput' => $counts['invalid-input'],
            'byStatus' => $counts,
            'unclassifiedNonExact' => $unclassified,
            'latencyBudgetExceeded' => $latencyExceeded,
            'memoryBudgetExceeded' => $memoryExceeded,
        ],
        'coverage' => [
            'covered' => array_keys($coverage),
            'missing' => $missingCoverage,
        ],
        'blockers' => $blockers,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($blockers === [] ? 0 : 1);
} catch (Throwable $exception) {
    failInfrastructure($exception->getMessage());
}

/** @return array<string, mixed> */
function decodeObjectFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException(sprintf('%s does not exist.', ucfirst($label)));
    }
    $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException(sprintf('%s is not an object.', ucfirst($label)));
    }
    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/** @return array<string, mixed> */
function requireObject(mixed $value, string $label): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException(sprintf('%s must be an object.', $label));
    }
    /** @var array<string, mixed> $value */
    return $value;
}

/** @return array<string, string> */
function requireIdentity(mixed $value, string $label): array
{
    $identity = requireObject($value, $label.' identity');
    $required = [
        'candidateIdentitySha256',
        'productVersion',
        'productBuildSha256',
        'packageVersion',
        'engineVersion',
        'engineSha256',
        'sourceTreeSha256',
        'runtimeArchiveSha256',
        'algorithmVersion',
        'stateSchemaVersion',
        'arithmeticBackend',
    ];
    requireOnlyKeys($identity, $required, $label.' identity');
    $normalized = [];
    foreach ($required as $field) {
        $normalized[$field] = requireNonEmptyString($identity[$field] ?? null, $label.' identity.'.$field);
    }
    foreach (['candidateIdentitySha256', 'productBuildSha256', 'engineSha256', 'sourceTreeSha256', 'runtimeArchiveSha256'] as $field) {
        requireSha256($normalized[$field], $label.' identity.'.$field);
    }
    if (!in_array($normalized['arithmeticBackend'], ['gmp', 'limbs-30'], true)) {
        throw new RuntimeException(sprintf('%s identity has an unsupported arithmetic backend.', $label));
    }

    return $normalized;
}

function requireNonEmptyString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException(sprintf('%s must be a non-empty string.', $label));
    }

    return $value;
}

function requireSha256(mixed $value, string $label): string
{
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        throw new RuntimeException(sprintf('%s must be a lowercase SHA-256.', $label));
    }

    return $value;
}

function nullableSha256(mixed $value, string $label): ?string
{
    if ($value === null) {
        return null;
    }

    return requireSha256($value, $label);
}

function requireNonNegativeInt(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException(sprintf('%s must be a non-negative integer.', $label));
    }

    return $value;
}

function requirePositiveInt(mixed $value, string $label): int
{
    $integer = requireNonNegativeInt($value, $label);
    if ($integer < 1) {
        throw new RuntimeException(sprintf('%s must be positive.', $label));
    }

    return $integer;
}

function requireNonNegativeNumber(mixed $value, string $label): float
{
    if ((!is_int($value) && !is_float($value)) || $value < 0 || !is_finite((float) $value)) {
        throw new RuntimeException(sprintf('%s must be a finite non-negative number.', $label));
    }

    return (float) $value;
}

function requirePositiveNumber(mixed $value, string $label): float
{
    $number = requireNonNegativeNumber($value, $label);
    if ($number <= 0) {
        throw new RuntimeException(sprintf('%s must be positive.', $label));
    }

    return $number;
}

/** @return list<string> */
function requireStringList(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value)) {
        throw new RuntimeException(sprintf('%s must be a list.', $label));
    }
    $result = [];
    foreach ($value as $item) {
        $result[] = requireNonEmptyString($item, $label.' item');
    }

    return $result;
}

function requireUtcTimestamp(mixed $value, string $label): void
{
    $timestamp = requireNonEmptyString($value, $label);
    if (preg_match('/Z$/', $timestamp) !== 1) {
        throw new RuntimeException(sprintf('%s must be expressed in UTC with a Z suffix.', $label));
    }
    new DateTimeImmutable($timestamp);
}

/**
 * @param array<string, mixed> $value
 * @param list<string> $allowed
 */
function requireOnlyKeys(array $value, array $allowed, string $label): void
{
    $unexpected = array_values(array_diff(array_keys($value), $allowed));
    if ($unexpected !== []) {
        throw new RuntimeException(sprintf('%s contains unexpected fields: %s.', $label, implode(', ', $unexpected)));
    }
}

/**
 * @param array<string, mixed> $value
 * @param list<string> $required
 */
function requireKeys(array $value, array $required, string $label): void
{
    $missing = array_values(array_diff($required, array_keys($value)));
    if ($missing !== []) {
        throw new RuntimeException(sprintf('%s is missing required fields: %s.', $label, implode(', ', $missing)));
    }
}

/** @param array<array-key, mixed> $value */
function rejectPersonalData(array $value, string $path): void
{
    foreach ($value as $key => $item) {
        $normalized = strtolower(str_replace(['-', '_'], '', (string) $key));
        if (in_array($normalized, SHADOW_FORBIDDEN_KEYS, true)) {
            throw new RuntimeException(sprintf('Personal-data key is forbidden in %s: %s.', $path, $key));
        }
        if (is_array($item)) {
            rejectPersonalData($item, $path.'.'.$key);
        }
    }
}

/** @return array<string, true> */
function fixtureCoverage(mixed $records, string $manifestDirectory): array
{
    if (!is_array($records) || !array_is_list($records)) {
        throw new RuntimeException('fixtureEvidence must be a list.');
    }
    $coverage = [];
    foreach ($records as $index => $record) {
        $record = requireObject($record, sprintf('fixtureEvidence %d', $index));
        requireOnlyKeys($record, ['feature', 'path', 'sha256'], sprintf('fixtureEvidence %d', $index));
        $feature = requireNonEmptyString($record['feature'] ?? null, sprintf('fixtureEvidence %d feature', $index));
        if (!in_array($feature, SHADOW_REQUIRED_COVERAGE, true)) {
            throw new RuntimeException(sprintf('fixtureEvidence %d has an unsupported feature.', $index));
        }
        $path = requireNonEmptyString($record['path'] ?? null, sprintf('fixtureEvidence %d path', $index));
        if (str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
            throw new RuntimeException(sprintf('fixtureEvidence %d has an unsafe path.', $index));
        }
        $expectedHash = requireSha256($record['sha256'] ?? null, sprintf('fixtureEvidence %d sha256', $index));
        $absolute = $manifestDirectory.DIRECTORY_SEPARATOR.$path;
        if (!is_file($absolute) || !hash_equals($expectedHash, (string) hash_file('sha256', $absolute))) {
            throw new RuntimeException(sprintf('fixtureEvidence %d is missing or has a SHA-256 mismatch.', $index));
        }
        $coverage[$feature] = true;
    }

    return $coverage;
}

function failInfrastructure(string $message): never
{
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.shadow-evidence-verification/1',
        'status' => 'ERROR',
        'code' => 'shadow_evidence.infrastructure',
        'message' => $message,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}
