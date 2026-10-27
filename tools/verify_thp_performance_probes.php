#!/usr/bin/env php
<?php

declare(strict_types=1);

$batchPath = $argv[1] ?? null;
$candidatePath = $argv[2] ?? null;
$artifactRoot = $argv[3] ?? null;

try {
    if (!is_string($batchPath) || !is_string($candidatePath) || !is_string($artifactRoot)) {
        throw new RuntimeException('Usage: verify_thp_performance_probes.php BATCH.json CANDIDATE.json ARTIFACT_ROOT');
    }
    $identity = thpPerformanceRunIdentityVerifier($candidatePath, $artifactRoot);
    $candidateIdentity = thpPerformanceRequireSha256($identity['candidateIdentitySha256'] ?? null, 'verified candidateIdentitySha256');
    $candidate = thpPerformanceDecodeFile($candidatePath, 'candidate identity');
    $product = thpPerformanceRequireObject($candidate['product'] ?? null, 'candidate.product');
    $runtime = thpPerformanceRequireObject($candidate['runtime'] ?? null, 'candidate.runtime');
    $standards = thpPerformanceRequireObject($candidate['standards'] ?? null, 'candidate.standards');
    $productCommit = thpPerformanceRequirePattern($product['sourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'candidate.product.sourceCommit');
    $backend = thpPerformanceRequireEnum($runtime['arithmeticBackend'] ?? null, ['gmp', 'limbs-30'], 'candidate.runtime.arithmeticBackend');
    $systems = thpPerformanceRequireUniqueStrings($standards['pairingSystems'] ?? null, 'candidate.standards.pairingSystems');

    $batch = thpPerformanceDecodeFile($batchPath, 'performance probe batch');
    thpPerformanceRejectPrivateKeys($batch);
    thpPerformanceRequireExactKeys($batch, [
        'schema', 'candidateIdentitySha256', 'productSourceCommit', 'migrationManifestSha256',
        'arithmeticBackend', 'supportedPlayerRange', 'requiredPlayerCounts', 'budgets', 'probes',
    ], 'batch');
    if (($batch['schema'] ?? null) !== 'openpairing.thp-performance-probe-batch/1') {
        throw new RuntimeException('Performance probe batch has an unsupported schema.');
    }
    if (!hash_equals($candidateIdentity, thpPerformanceRequireSha256($batch['candidateIdentitySha256'] ?? null, 'candidateIdentitySha256'))) {
        throw new RuntimeException('Performance probe batch is not bound to the verified candidate identity.');
    }
    if ($productCommit !== thpPerformanceRequirePattern($batch['productSourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'productSourceCommit')) {
        throw new RuntimeException('Performance probe product source commit differs from the verified candidate.');
    }
    $migrationHash = thpPerformanceRequireSha256($batch['migrationManifestSha256'] ?? null, 'migrationManifestSha256');
    if ($backend !== thpPerformanceRequireEnum($batch['arithmeticBackend'] ?? null, ['gmp', 'limbs-30'], 'arithmeticBackend')) {
        throw new RuntimeException('Performance probe arithmetic backend differs from the verified candidate.');
    }

    $range = thpPerformanceRequireObject($batch['supportedPlayerRange'] ?? null, 'supportedPlayerRange');
    thpPerformanceRequireExactKeys($range, ['minimum', 'maximum'], 'supportedPlayerRange');
    $minimumPlayers = thpPerformanceRequireInteger($range['minimum'] ?? null, 'supportedPlayerRange.minimum', 2, 4096);
    $maximumPlayers = thpPerformanceRequireInteger($range['maximum'] ?? null, 'supportedPlayerRange.maximum', 2, 4096);
    if ($minimumPlayers > $maximumPlayers) {
        throw new RuntimeException('supportedPlayerRange minimum exceeds maximum.');
    }
    $playerCounts = thpPerformanceRequireIntegerList($batch['requiredPlayerCounts'] ?? null, 'requiredPlayerCounts', 3, 16, 2, 4096);
    $sortedCounts = $playerCounts;
    sort($sortedCounts, SORT_NUMERIC);
    if ($sortedCounts !== $playerCounts || count(array_unique($playerCounts)) !== count($playerCounts)) {
        throw new RuntimeException('requiredPlayerCounts must be strictly increasing and unique.');
    }
    foreach ($playerCounts as $players) {
        if ($players < $minimumPlayers || $players > $maximumPlayers) {
            throw new RuntimeException('requiredPlayerCounts contains a value outside supportedPlayerRange.');
        }
    }
    if ($playerCounts[count($playerCounts) - 1] !== $maximumPlayers) {
        throw new RuntimeException('requiredPlayerCounts must include the declared maximum supported player count.');
    }

    $budgets = thpPerformanceRequireObject($batch['budgets'] ?? null, 'budgets');
    thpPerformanceRequireExactKeys($budgets, ['maximumTurnElapsedMs', 'maximumPeakMemoryBytes'], 'budgets');
    $maximumElapsed = thpPerformanceRequirePositiveNumber($budgets['maximumTurnElapsedMs'] ?? null, 'budgets.maximumTurnElapsedMs');
    $maximumMemory = thpPerformanceRequireInteger($budgets['maximumPeakMemoryBytes'] ?? null, 'budgets.maximumPeakMemoryBytes', 1, PHP_INT_MAX);

    $rawProbes = $batch['probes'] ?? null;
    $expectedProbeCount = count($systems) * count($playerCounts);
    if (!is_array($rawProbes) || !array_is_list($rawProbes) || count($rawProbes) !== $expectedProbeCount) {
        throw new RuntimeException(sprintf('probes must contain every declared-system/player-count combination (%d total).', $expectedProbeCount));
    }
    $probes = [];
    $probeIds = [];
    foreach ($rawProbes as $index => $rawProbe) {
        $probe = thpPerformanceRequireProbe($rawProbe, sprintf('probes[%d]', $index));
        if (isset($probeIds[$probe['probeId']])) {
            throw new RuntimeException('Duplicate performance probeId: '.$probe['probeId'].'.');
        }
        $probeIds[$probe['probeId']] = true;
        if (!hash_equals($candidateIdentity, $probe['candidateIdentitySha256'])) {
            throw new RuntimeException('Performance probe is not bound to the verified candidate: '.$probe['probeId'].'.');
        }
        if (!in_array($probe['system'], $systems, true)) {
            throw new RuntimeException('Performance probe uses an undeclared pairing system: '.$probe['probeId'].'.');
        }
        if (!in_array($probe['players'], $playerCounts, true)) {
            throw new RuntimeException('Performance probe uses an undeclared player count: '.$probe['probeId'].'.');
        }
        $key = $probe['system'].'|'.$probe['players'];
        if (isset($probes[$key])) {
            throw new RuntimeException('Duplicate performance system/player-count probe: '.$key.'.');
        }
        $probes[$key] = $probe;
    }
    foreach ($systems as $system) {
        foreach ($playerCounts as $players) {
            if (!isset($probes[$system.'|'.$players])) {
                throw new RuntimeException('Missing performance probe: '.$system.' at '.$players.' players.');
            }
        }
    }

    $violations = [];
    $sampleCount = 0;
    $observedMaximumElapsed = 0.0;
    $observedMaximumMemory = 0;
    foreach ($probes as $probe) {
        if ($probe['round'] >= $probe['players']) {
            $violations[] = $probe['probeId'].'.round_not_below_players';
        }
        if ($probe['writeCount'] !== 0) {
            $violations[] = $probe['probeId'].'.unexpected_write';
        }
        if (count($probe['samples']) !== $probe['repetitions']) {
            throw new RuntimeException('Performance probe repetitions/sample denominator mismatch: '.$probe['probeId'].'.');
        }
        $sampleIds = [];
        $functionalHashes = [];
        foreach ($probe['samples'] as $sample) {
            ++$sampleCount;
            if (isset($sampleIds[$sample['sampleId']])) {
                throw new RuntimeException('Duplicate sampleId within performance probe: '.$probe['probeId'].'.');
            }
            $sampleIds[$sample['sampleId']] = true;
            $functionalHashes[$sample['functionalOutputSha256']] = true;
            $observedMaximumElapsed = max($observedMaximumElapsed, $sample['totalElapsedMs']);
            $observedMaximumMemory = max($observedMaximumMemory, $sample['peakMemoryBytes']);
            if ($sample['status'] !== 'success') {
                $violations[] = $probe['probeId'].'.'.$sample['sampleId'].'.not_success';
            }
            if ($sample['totalElapsedMs'] > $maximumElapsed) {
                $violations[] = $probe['probeId'].'.'.$sample['sampleId'].'.elapsed_budget';
            }
            if ($sample['peakMemoryBytes'] > $maximumMemory) {
                $violations[] = $probe['probeId'].'.'.$sample['sampleId'].'.memory_budget';
            }
        }
        if (count($functionalHashes) !== 1) {
            $violations[] = $probe['probeId'].'.non_deterministic_output';
        }
    }
    $violations = array_values(array_unique($violations));
    $status = $violations === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.thp-performance-probe-verification/1',
        'status' => $status,
        'candidateIdentitySha256' => $candidateIdentity,
        'productSourceCommit' => $productCommit,
        'migrationManifestSha256' => $migrationHash,
        'arithmeticBackend' => $backend,
        'declaredPairingSystems' => $systems,
        'supportedPlayerRange' => ['minimum' => $minimumPlayers, 'maximum' => $maximumPlayers],
        'requiredPlayerCounts' => $playerCounts,
        'budgets' => ['maximumTurnElapsedMs' => $maximumElapsed, 'maximumPeakMemoryBytes' => $maximumMemory],
        'probeCount' => count($probes),
        'sampleCount' => $sampleCount,
        'observedMaximumTurnElapsedMs' => $observedMaximumElapsed,
        'observedMaximumPeakMemoryBytes' => $observedMaximumMemory,
        'blockingCodes' => $violations,
        'batchSha256' => hash_file('sha256', $batchPath),
        'boundary' => [
            'productPathMeasuredByVerifier' => false,
            'databaseAccessPerformed' => false,
            'databaseMutationPerformed' => false,
            'productionMutationPerformed' => false,
            'submissionGateClosed' => false,
            'note' => 'GO validates supplied exact-product measurements. The product harness must measure the complete pre-write snapshot/mapping/engine/validation route on the declared target environment.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-performance-probe-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_performance_probe.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/** @return array<string, mixed> */
function thpPerformanceRunIdentityVerifier(string $candidatePath, string $artifactRoot): array
{
    $process = proc_open([
        PHP_BINARY, __DIR__.'/verify_thp_candidate_identity.php', $candidatePath, $artifactRoot,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
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
    return thpPerformanceDecodeJson((string) $stdout, 'candidate identity verification');
}

/** @return array<string, mixed> */
function thpPerformanceDecodeFile(string $path, string $label): array
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
    return thpPerformanceDecodeJson($contents, $label);
}

/** @return array<string, mixed> */
function thpPerformanceDecodeJson(string $json, string $label): array
{
    return thpPerformanceRequireObject(json_decode($json, true, 64, JSON_THROW_ON_ERROR), $label);
}

/** @return array<string, mixed> */
function thpPerformanceRequireObject(mixed $value, string $label): array
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
function thpPerformanceRequireExactKeys(array $value, array $keys, string $label): void
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

/**
 * @return array{
 *   probeId:string,candidateIdentitySha256:string,system:string,players:int,round:int,repetitions:int,
 *   writeCount:int,samples:list<array{sampleId:string,totalElapsedMs:float,peakMemoryBytes:int,functionalOutputSha256:string,status:string}>
 * }
 */
function thpPerformanceRequireProbe(mixed $value, string $label): array
{
    $probe = thpPerformanceRequireObject($value, $label);
    thpPerformanceRequireExactKeys($probe, [
        'probeId', 'candidateIdentitySha256', 'system', 'players', 'round', 'repetitions', 'writeCount', 'samples',
    ], $label);
    $repetitions = thpPerformanceRequireInteger($probe['repetitions'] ?? null, $label.'.repetitions', 3, 20);
    $rawSamples = $probe['samples'] ?? null;
    if (!is_array($rawSamples) || !array_is_list($rawSamples) || count($rawSamples) < 3 || count($rawSamples) > 20) {
        throw new RuntimeException($label.'.samples must be a bounded list of 3..20 samples.');
    }
    $samples = [];
    foreach ($rawSamples as $index => $rawSample) {
        $samples[] = thpPerformanceRequireSample($rawSample, sprintf('%s.samples[%d]', $label, $index));
    }
    return [
        'probeId' => thpPerformanceRequirePattern($probe['probeId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.probeId'),
        'candidateIdentitySha256' => thpPerformanceRequireSha256($probe['candidateIdentitySha256'] ?? null, $label.'.candidateIdentitySha256'),
        'system' => thpPerformanceRequireBoundedString($probe['system'] ?? null, $label.'.system', 128),
        'players' => thpPerformanceRequireInteger($probe['players'] ?? null, $label.'.players', 2, 4096),
        'round' => thpPerformanceRequireInteger($probe['round'] ?? null, $label.'.round', 1, 256),
        'repetitions' => $repetitions,
        'writeCount' => thpPerformanceRequireInteger($probe['writeCount'] ?? null, $label.'.writeCount', 0, 1),
        'samples' => $samples,
    ];
}

/** @return array{sampleId:string,totalElapsedMs:float,peakMemoryBytes:int,functionalOutputSha256:string,status:string} */
function thpPerformanceRequireSample(mixed $value, string $label): array
{
    $sample = thpPerformanceRequireObject($value, $label);
    thpPerformanceRequireExactKeys($sample, ['sampleId', 'totalElapsedMs', 'peakMemoryBytes', 'functionalOutputSha256', 'status'], $label);
    return [
        'sampleId' => thpPerformanceRequirePattern($sample['sampleId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $label.'.sampleId'),
        'totalElapsedMs' => thpPerformanceRequireNonNegativeNumber($sample['totalElapsedMs'] ?? null, $label.'.totalElapsedMs'),
        'peakMemoryBytes' => thpPerformanceRequireInteger($sample['peakMemoryBytes'] ?? null, $label.'.peakMemoryBytes', 1, PHP_INT_MAX),
        'functionalOutputSha256' => thpPerformanceRequireSha256($sample['functionalOutputSha256'] ?? null, $label.'.functionalOutputSha256'),
        'status' => thpPerformanceRequireEnum($sample['status'] ?? null, ['success', 'error'], $label.'.status'),
    ];
}

function thpPerformanceRequireString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function thpPerformanceRequireBoundedString(mixed $value, string $label, int $limit): string
{
    $value = thpPerformanceRequireString($value, $label);
    if (strlen($value) > $limit) {
        throw new RuntimeException($label.' exceeds its byte limit.');
    }
    return $value;
}

function thpPerformanceRequirePattern(mixed $value, string $pattern, string $label): string
{
    $value = thpPerformanceRequireString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' has an invalid format.');
    }
    return $value;
}

function thpPerformanceRequireSha256(mixed $value, string $label): string
{
    return thpPerformanceRequirePattern($value, '/^[a-f0-9]{64}$/D', $label);
}

/** @param list<string> $allowed */
function thpPerformanceRequireEnum(mixed $value, array $allowed, string $label): string
{
    $value = thpPerformanceRequireString($value, $label);
    if (!in_array($value, $allowed, true)) {
        throw new RuntimeException($label.' has an unsupported value.');
    }
    return $value;
}

function thpPerformanceRequireInteger(mixed $value, string $label, int $minimum, int $maximum): int
{
    if (!is_int($value) || $value < $minimum || $value > $maximum) {
        throw new RuntimeException(sprintf('%s must be an integer between %d and %d.', $label, $minimum, $maximum));
    }
    return $value;
}

function thpPerformanceRequirePositiveNumber(mixed $value, string $label): float
{
    $value = thpPerformanceRequireNonNegativeNumber($value, $label);
    if ($value <= 0.0) {
        throw new RuntimeException($label.' must be positive.');
    }
    return $value;
}

function thpPerformanceRequireNonNegativeNumber(mixed $value, string $label): float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
        throw new RuntimeException($label.' must be a finite non-negative number.');
    }
    return (float) $value;
}

/** @return list<int> */
function thpPerformanceRequireIntegerList(mixed $value, string $label, int $minimumItems, int $maximumItems, int $minimum, int $maximum): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < $minimumItems || count($value) > $maximumItems) {
        throw new RuntimeException($label.' has an invalid denominator.');
    }
    $result = [];
    foreach ($value as $index => $item) {
        $result[] = thpPerformanceRequireInteger($item, sprintf('%s[%d]', $label, $index), $minimum, $maximum);
    }
    return $result;
}

/** @return list<string> */
function thpPerformanceRequireUniqueStrings(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 32) {
        throw new RuntimeException($label.' must be a bounded non-empty list.');
    }
    $result = [];
    foreach ($value as $index => $item) {
        $item = thpPerformanceRequireBoundedString($item, sprintf('%s[%d]', $label, $index), 128);
        if (in_array($item, $result, true)) {
            throw new RuntimeException($label.' contains a duplicate.');
        }
        $result[] = $item;
    }
    return $result;
}

function thpPerformanceRejectPrivateKeys(mixed $value, string $path = 'batch'): void
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
        thpPerformanceRejectPrivateKeys($item, $path.'.'.$keyText);
    }
}
