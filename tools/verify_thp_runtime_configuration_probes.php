#!/usr/bin/env php
<?php

declare(strict_types=1);

$batchPath = $argv[1] ?? null;
$candidatePath = $argv[2] ?? null;
$artifactRoot = $argv[3] ?? null;

try {
    if (!is_string($batchPath) || !is_string($candidatePath) || !is_string($artifactRoot)) {
        throw new RuntimeException('Usage: verify_thp_runtime_configuration_probes.php BATCH.json CANDIDATE.json ARTIFACT_ROOT');
    }
    $identity = configRunVerifier([
        PHP_BINARY, __DIR__.'/verify_thp_candidate_identity.php', $candidatePath, $artifactRoot,
    ]);
    $candidateIdentity = configSha($identity['candidateIdentitySha256'] ?? null, 'verified candidateIdentitySha256');
    $candidate = configDecodeFile($candidatePath, 'candidate identity');
    $product = configObject($candidate['product'] ?? null, 'candidate.product');
    $expectedCommit = configPattern($product['sourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'candidate.product.sourceCommit');

    $batch = configDecodeFile($batchPath, 'runtime configuration probe batch');
    configRejectPrivateKeys($batch);
    configExactKeys($batch, [
        'schema', 'candidateIdentitySha256', 'productSourceCommit', 'migrationManifestSha256',
        'requiredConfigurationSlots', 'slotProbes', 'failureScenarios',
    ], 'batch');
    if (($batch['schema'] ?? null) !== 'openpairing.thp-runtime-configuration-probe-batch/1') {
        throw new RuntimeException('Runtime configuration probe batch has an unsupported schema.');
    }
    if (!hash_equals($candidateIdentity, configSha($batch['candidateIdentitySha256'] ?? null, 'candidateIdentitySha256'))) {
        throw new RuntimeException('Runtime configuration probe batch is not bound to the verified candidate identity.');
    }
    if ($expectedCommit !== configPattern($batch['productSourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'productSourceCommit')) {
        throw new RuntimeException('Runtime configuration probe batch product source commit differs from the verified candidate.');
    }
    configSha($batch['migrationManifestSha256'] ?? null, 'migrationManifestSha256');
    $slots = configUniqueSlotIds($batch['requiredConfigurationSlots'] ?? null, 'requiredConfigurationSlots');

    $rawProbes = $batch['slotProbes'] ?? null;
    if (!is_array($rawProbes) || !array_is_list($rawProbes) || count($rawProbes) !== count($slots)) {
        throw new RuntimeException('slotProbes must contain exactly one probe per required configuration slot.');
    }
    $probes = [];
    $probeIds = [];
    foreach ($rawProbes as $index => $rawProbe) {
        $probe = configSlotProbe($rawProbe, sprintf('slotProbes[%d]', $index));
        if (isset($probeIds[$probe['probeId']])) {
            throw new RuntimeException('Duplicate runtime configuration probeId: '.$probe['probeId'].'.');
        }
        if (isset($probes[$probe['slotId']])) {
            throw new RuntimeException('Duplicate runtime configuration slot probe: '.$probe['slotId'].'.');
        }
        if (!hash_equals($candidateIdentity, $probe['candidateIdentitySha256'])) {
            throw new RuntimeException('Runtime configuration slot probe is not bound to the verified candidate: '.$probe['probeId'].'.');
        }
        $probeIds[$probe['probeId']] = true;
        $probes[$probe['slotId']] = $probe;
    }
    foreach ($slots as $slot) {
        if (!isset($probes[$slot])) {
            throw new RuntimeException('Missing runtime configuration slot probe: '.$slot.'.');
        }
    }

    $rawFailures = $batch['failureScenarios'] ?? null;
    if (!is_array($rawFailures) || !array_is_list($rawFailures) || count($rawFailures) !== 3) {
        throw new RuntimeException('failureScenarios must contain exactly the three required cases.');
    }
    $failures = [];
    $scenarioIds = [];
    foreach ($rawFailures as $index => $rawFailure) {
        $failure = configFailure($rawFailure, sprintf('failureScenarios[%d]', $index));
        if (isset($scenarioIds[$failure['scenarioId']])) {
            throw new RuntimeException('Duplicate runtime configuration scenarioId: '.$failure['scenarioId'].'.');
        }
        if (isset($failures[$failure['kind']])) {
            throw new RuntimeException('Duplicate runtime configuration failure kind: '.$failure['kind'].'.');
        }
        if (!in_array($failure['slotId'], $slots, true)) {
            throw new RuntimeException('Runtime configuration failure references an undeclared slot.');
        }
        if (!hash_equals($candidateIdentity, $failure['candidateIdentitySha256'])) {
            throw new RuntimeException('Runtime configuration failure is not bound to the verified candidate: '.$failure['scenarioId'].'.');
        }
        $scenarioIds[$failure['scenarioId']] = true;
        $failures[$failure['kind']] = $failure;
    }
    foreach (['missing-required', 'invalid-scope', 'redaction-probe'] as $kind) {
        if (!isset($failures[$kind])) {
            throw new RuntimeException('Missing runtime configuration failure kind: '.$kind.'.');
        }
    }

    $violations = [];
    foreach ($slots as $slot) {
        $probe = $probes[$slot];
        if ($probe['configurationReadCount'] !== 1) {
            $violations[] = $slot.'.configuration_read';
        }
        $expectsDestination = $probe['declaredNetworkScope'] !== 'none';
        if ($probe['observedNetworkDestinationCount'] !== ($expectsDestination ? 1 : 0)) {
            $violations[] = $slot.'.network_destination_count';
        }
        if (($probe['networkDestinationSha256'] !== null) !== $expectsDestination) {
            $violations[] = $slot.'.network_destination_identity';
        }
        foreach (['artifactExposureCount', 'logExposureCount', 'evidenceExposureCount', 'diagnosticExposureCount', 'responseExposureCount'] as $field) {
            if ($probe[$field] !== 0) {
                $violations[] = $slot.'.'.$field;
            }
        }
        if ($probe['writeCount'] !== 0 || $probe['outcome'] !== 'accepted' || $probe['diagnosticCode'] !== null) {
            $violations[] = $slot.'.acceptance';
        }
    }
    configExpectFailure($violations, $failures['missing-required'], 0, 'runtime_config.required_missing');
    configExpectFailure($violations, $failures['invalid-scope'], 1, 'runtime_config.scope_invalid');
    configExpectFailure($violations, $failures['redaction-probe'], 1, 'runtime_config.probe_failure_redacted');

    $violations = array_values(array_unique($violations));
    sort($violations, SORT_STRING);
    $status = $violations === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.thp-runtime-configuration-probe-verification/1',
        'status' => $status,
        'candidateIdentitySha256' => $candidateIdentity,
        'productSourceCommit' => $expectedCommit,
        'migrationManifestSha256' => $batch['migrationManifestSha256'],
        'requiredConfigurationSlots' => $slots,
        'slotProbeCount' => count($probes),
        'failureScenarioCount' => count($failures),
        'blockingCodes' => $violations,
        'batchSha256' => hash_file('sha256', $batchPath),
        'boundary' => [
            'configurationInjectedByVerifier' => false,
            'sensitiveValuesReadByVerifier' => false,
            'networkAccessPerformed' => false,
            'databaseAccessPerformed' => false,
            'databaseMutationPerformed' => false,
            'productionMutationPerformed' => false,
            'submissionGateClosed' => false,
            'note' => 'GO validates supplied exact-product configuration observations; it does not inspect secret values or configure ChessPairings.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($status === 'GO' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-runtime-configuration-probe-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_runtime_configuration_probe.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/** @param list<string> $command @return array<string, mixed> */
function configRunVerifier(array $command): array
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
    return configDecodeJson((string) $stdout, 'candidate identity verification');
}

/** @return array<string, mixed> */
function configDecodeFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    $size = filesize($path);
    if (!is_int($size) || $size > 8_388_608) {
        throw new RuntimeException($label.' exceeds the 8 MiB verification limit.');
    }
    return configDecodeJson((string) file_get_contents($path), $label);
}

/** @return array<string, mixed> */
function configDecodeJson(string $json, string $label): array
{
    return configObject(json_decode($json, true, 64, JSON_THROW_ON_ERROR), $label);
}

/** @return array<string, mixed> */
function configObject(mixed $value, string $label): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException($label.' must be an object.');
    }
    /** @var array<string, mixed> $value */
    return $value;
}

/** @param array<string, mixed> $value @param list<string> $keys */
function configExactKeys(array $value, array $keys, string $label): void
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
function configSlotProbe(mixed $value, string $label): array
{
    $probe = configObject($value, $label);
    configExactKeys($probe, [
        'probeId', 'slotId', 'candidateIdentitySha256', 'injectionSource', 'configurationReadCount',
        'declaredNetworkScope', 'observedNetworkDestinationCount', 'networkDestinationSha256',
        'artifactExposureCount', 'logExposureCount', 'evidenceExposureCount', 'diagnosticExposureCount',
        'responseExposureCount', 'writeCount', 'outcome', 'diagnosticCode',
    ], $label);
    $result = [
        'probeId' => configPattern($probe['probeId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.probeId'),
        'slotId' => configSlotId($probe['slotId'] ?? null, $label.'.slotId'),
        'candidateIdentitySha256' => configSha($probe['candidateIdentitySha256'] ?? null, $label.'.candidateIdentitySha256'),
        'injectionSource' => configEnum($probe['injectionSource'] ?? null, ['environment', 'secret-store'], $label.'.injectionSource'),
        'configurationReadCount' => configZeroOrOne($probe['configurationReadCount'] ?? null, $label.'.configurationReadCount'),
        'declaredNetworkScope' => configEnum($probe['declaredNetworkScope'] ?? null, ['none', 'local-only', 'single-declared-endpoint'], $label.'.declaredNetworkScope'),
        'observedNetworkDestinationCount' => configZeroOrOne($probe['observedNetworkDestinationCount'] ?? null, $label.'.observedNetworkDestinationCount'),
        'networkDestinationSha256' => $probe['networkDestinationSha256'] === null ? null : configSha($probe['networkDestinationSha256'], $label.'.networkDestinationSha256'),
    ];
    foreach (['artifactExposureCount', 'logExposureCount', 'evidenceExposureCount', 'diagnosticExposureCount', 'responseExposureCount', 'writeCount'] as $field) {
        $result[$field] = configNonNegative($probe[$field] ?? null, $label.'.'.$field);
    }
    $result['outcome'] = configEnum($probe['outcome'] ?? null, ['accepted', 'rejected'], $label.'.outcome');
    $result['diagnosticCode'] = $probe['diagnosticCode'] === null ? null : configBoundedString($probe['diagnosticCode'], $label.'.diagnosticCode', 128);
    return $result;
}

/** @return array<string, mixed> */
function configFailure(mixed $value, string $label): array
{
    $failure = configObject($value, $label);
    configExactKeys($failure, [
        'scenarioId', 'kind', 'slotId', 'candidateIdentitySha256', 'configurationReadCount',
        'networkCallCount', 'writeCount', 'secretValueExposureCount', 'outcome', 'diagnosticCode',
    ], $label);
    return [
        'scenarioId' => configPattern($failure['scenarioId'] ?? null, '/^[A-Za-z0-9][A-Za-z0-9._-]{15,127}$/D', $label.'.scenarioId'),
        'kind' => configEnum($failure['kind'] ?? null, ['missing-required', 'invalid-scope', 'redaction-probe'], $label.'.kind'),
        'slotId' => configSlotId($failure['slotId'] ?? null, $label.'.slotId'),
        'candidateIdentitySha256' => configSha($failure['candidateIdentitySha256'] ?? null, $label.'.candidateIdentitySha256'),
        'configurationReadCount' => configZeroOrOne($failure['configurationReadCount'] ?? null, $label.'.configurationReadCount'),
        'networkCallCount' => configNonNegative($failure['networkCallCount'] ?? null, $label.'.networkCallCount'),
        'writeCount' => configNonNegative($failure['writeCount'] ?? null, $label.'.writeCount'),
        'secretValueExposureCount' => configNonNegative($failure['secretValueExposureCount'] ?? null, $label.'.secretValueExposureCount'),
        'outcome' => configEnum($failure['outcome'] ?? null, ['accepted', 'rejected'], $label.'.outcome'),
        'diagnosticCode' => configBoundedString($failure['diagnosticCode'] ?? null, $label.'.diagnosticCode', 128),
    ];
}

/** @param list<string> $violations @param array<string, mixed> $failure */
function configExpectFailure(array &$violations, array $failure, int $reads, string $diagnostic): void
{
    foreach ([
        'configurationReadCount' => $reads, 'networkCallCount' => 0, 'writeCount' => 0,
        'secretValueExposureCount' => 0, 'outcome' => 'rejected', 'diagnosticCode' => $diagnostic,
    ] as $field => $expected) {
        if (($failure[$field] ?? null) !== $expected) {
            $violations[] = $failure['kind'].'.'.$field;
        }
    }
}

function configString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function configBoundedString(mixed $value, string $label, int $limit): string
{
    $value = configString($value, $label);
    if (strlen($value) > $limit) {
        throw new RuntimeException($label.' exceeds its byte limit.');
    }
    return $value;
}

function configPattern(mixed $value, string $pattern, string $label): string
{
    $value = configString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' has an invalid format.');
    }
    return $value;
}

function configSha(mixed $value, string $label): string
{
    return configPattern($value, '/^[a-f0-9]{64}$/D', $label);
}

function configSlotId(mixed $value, string $label): string
{
    return configPattern($value, '/^[a-z][a-z0-9._-]{7,127}$/D', $label);
}

/** @param list<string> $allowed */
function configEnum(mixed $value, array $allowed, string $label): string
{
    $value = configString($value, $label);
    if (!in_array($value, $allowed, true)) {
        throw new RuntimeException($label.' has an unsupported value.');
    }
    return $value;
}

function configNonNegative(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0) {
        throw new RuntimeException($label.' must be a non-negative integer.');
    }
    return $value;
}

function configZeroOrOne(mixed $value, string $label): int
{
    if (!is_int($value) || $value < 0 || $value > 1) {
        throw new RuntimeException($label.' must be zero or one.');
    }
    return $value;
}

/** @return list<string> */
function configUniqueSlotIds(mixed $value, string $label): array
{
    if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 32) {
        throw new RuntimeException($label.' must be a bounded non-empty list.');
    }
    $result = [];
    foreach ($value as $index => $item) {
        $item = configSlotId($item, sprintf('%s[%d]', $label, $index));
        if (in_array($item, $result, true)) {
            throw new RuntimeException($label.' contains a duplicate value.');
        }
        $result[] = $item;
    }
    return $result;
}

function configRejectPrivateKeys(mixed $value, string $path = 'batch'): void
{
    if (!is_array($value)) {
        return;
    }
    $forbidden = ['name', 'email', 'password', 'token', 'secret', 'fideid', 'federationid', 'birthdate', 'credential'];
    foreach ($value as $key => $item) {
        $keyText = is_string($key) ? $key : (string) $key;
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $keyText));
        if (in_array($normalized, $forbidden, true)) {
            throw new RuntimeException($path.' contains forbidden personal/secret field '.$keyText.'.');
        }
        configRejectPrivateKeys($item, $path.'.'.$keyText);
    }
}
