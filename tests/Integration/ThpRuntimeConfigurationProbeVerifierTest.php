<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpRuntimeConfigurationProbeVerifierTest extends TestCase
{
    private string $temporary;
    private string $candidate;
    private string $candidateIdentity;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-config-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
        $this->candidate = $this->writeCandidate();
        [$exit, $stdout, $stderr] = $this->runCommand([
            PHP_BINARY, dirname(__DIR__, 2).'/tools/verify_thp_candidate_identity.php', $this->candidate, $this->temporary,
        ]);
        self::assertSame(0, $exit, $stderr);
        $identity = $this->decodeObject($stdout)['candidateIdentitySha256'] ?? null;
        self::assertIsString($identity);
        $this->candidateIdentity = $identity;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testCompleteRuntimeConfigurationBatchPasses(): void
    {
        [$exit, $stdout, $stderr] = $this->runVerifier($this->validBatch());

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(2, $report['slotProbeCount']);
        self::assertSame(3, $report['failureScenarioCount']);
        self::assertSame([], $report['blockingCodes']);
        $boundary = $report['boundary'] ?? null;
        self::assertIsArray($boundary);
        self::assertFalse($boundary['sensitiveValuesReadByVerifier'] ?? null);
        self::assertFalse($boundary['submissionGateClosed'] ?? null);
    }

    public function testLogExposureIsNoGo(): void
    {
        $batch = $this->validBatch();
        $probe = $this->probe($batch, 'database-auth');
        $probe['logExposureCount'] = 1;
        $this->replaceProbe($batch, $probe);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('database-auth.logExposureCount', $this->blockingCodes($stdout));
    }

    public function testNetworkScopeMustMatchObservedDestination(): void
    {
        $batch = $this->validBatch();
        $probe = $this->probe($batch, 'mail-delivery-auth');
        $probe['observedNetworkDestinationCount'] = 0;
        $probe['networkDestinationSha256'] = null;
        $this->replaceProbe($batch, $probe);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('mail-delivery-auth.network_destination_count', $this->blockingCodes($stdout));
        self::assertContains('mail-delivery-auth.network_destination_identity', $this->blockingCodes($stdout));
    }

    public function testMissingConfigurationMayNotWrite(): void
    {
        $batch = $this->validBatch();
        $failure = $this->failure($batch, 'missing-required');
        $failure['writeCount'] = 1;
        $this->replaceFailure($batch, $failure);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('missing-required.writeCount', $this->blockingCodes($stdout));
    }

    public function testMissingFailureScenarioIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $failures = $batch['failureScenarios'] ?? null;
        self::assertIsArray($failures);
        array_pop($failures);
        $batch['failureScenarios'] = $failures;

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('exactly the three required', $stderr);
    }

    public function testFailureMayNotReferenceUndeclaredSlot(): void
    {
        $batch = $this->validBatch();
        $failure = $this->failure($batch, 'invalid-scope');
        $failure['slotId'] = 'undeclared-auth';
        $this->replaceFailure($batch, $failure);

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('undeclared slot', $stderr);
    }

    public function testCandidateIdentityDriftIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $batch['candidateIdentitySha256'] = str_repeat('9', 64);

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('not bound to the verified candidate', $stderr);
    }

    public function testPrivateFieldIsRejectedWithoutEmittingItsValue(): void
    {
        $batch = $this->validBatch();
        $batch['password'] = 'private-value-never-emit';

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('forbidden personal/secret field password', $stderr);
        self::assertStringNotContainsString('private-value-never-emit', $stderr);
    }

    /**
     * @return array<string, mixed>
     */
    private function validBatch(): array
    {
        return [
            'schema' => 'openpairing.thp-runtime-configuration-probe-batch/1',
            'candidateIdentitySha256' => $this->candidateIdentity,
            'productSourceCommit' => str_repeat('1', 40),
            'migrationManifestSha256' => str_repeat('5', 64),
            'requiredConfigurationSlots' => ['database-auth', 'mail-delivery-auth'],
            'slotProbes' => [
                $this->slotProbe('database-auth', 'environment', 'local-only'),
                $this->slotProbe('mail-delivery-auth', 'secret-store', 'single-declared-endpoint'),
            ],
            'failureScenarios' => [
                $this->failureScenario('config-missing-required-001', 'missing-required', 0, 'runtime_config.required_missing'),
                $this->failureScenario('config-invalid-scope-001', 'invalid-scope', 1, 'runtime_config.scope_invalid'),
                $this->failureScenario('config-redaction-probe-001', 'redaction-probe', 1, 'runtime_config.probe_failure_redacted'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slotProbe(string $slot, string $source, string $scope): array
    {
        return [
            'probeId' => 'runtime-slot-'.$slot.'-001', 'slotId' => $slot,
            'candidateIdentitySha256' => $this->candidateIdentity, 'injectionSource' => $source,
            'configurationReadCount' => 1, 'declaredNetworkScope' => $scope,
            'observedNetworkDestinationCount' => 1, 'networkDestinationSha256' => hash('sha256', $slot.'-destination'),
            'artifactExposureCount' => 0, 'logExposureCount' => 0, 'evidenceExposureCount' => 0,
            'diagnosticExposureCount' => 0, 'responseExposureCount' => 0, 'writeCount' => 0,
            'outcome' => 'accepted', 'diagnosticCode' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failureScenario(string $id, string $kind, int $reads, string $diagnostic): array
    {
        return [
            'scenarioId' => $id, 'kind' => $kind, 'slotId' => 'database-auth',
            'candidateIdentitySha256' => $this->candidateIdentity, 'configurationReadCount' => $reads,
            'networkCallCount' => 0, 'writeCount' => 0, 'secretValueExposureCount' => 0,
            'outcome' => 'rejected', 'diagnosticCode' => $diagnostic,
        ];
    }

    private function writeCandidate(): string
    {
        $files = [
            'product-installer' => 'candidate-installer.bin', 'openpairing-runtime' => 'openpairing-runtime.zip',
            'ptc' => 'openpairing-ptc.php', 'rtg' => 'openpairing-rtg.php',
        ];
        $artifacts = [];
        foreach ($files as $role => $path) {
            self::assertIsInt(file_put_contents($this->temporary.'/'.$path, 'artifact-'.$role));
            $artifacts[] = ['role' => $role, 'path' => $path, 'sha256' => hash_file('sha256', $this->temporary.'/'.$path)];
        }
        $manifest = $this->temporary.'/candidate.json';
        self::assertIsInt(file_put_contents($manifest, json_encode([
            'schema' => 'openpairing.thp-candidate-identity/1', 'candidateName' => 'ChessPairings-OpenPairing-1.0.0',
            'product' => ['name' => 'ChessPairings', 'version' => '1.0.0', 'sourceCommit' => str_repeat('1', 40), 'fideMode' => 'fide-individual'],
            'package' => [
                'name' => 'openpairing/openpairing-php', 'version' => '1.0.0', 'sourceCommit' => str_repeat('2', 40),
                'sourceTreeSha256' => str_repeat('3', 64), 'engineVersion' => 'fide-dutch-2026.global/1', 'engineSha256' => str_repeat('4', 64),
            ],
            'runtime' => ['osProfile' => 'Ubuntu 24.04 x64', 'phpVersion' => '8.3.6', 'arithmeticBackend' => 'gmp'],
            'standards' => ['pairingSystems' => ['Dutch 2026'], 'trfVersions' => ['TRF26'], 'schemaVersions' => ['tournament-v1']],
            'artifacts' => $artifacts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        return $manifest;
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function probe(array $batch, string $slot): array
    {
        $probes = $batch['slotProbes'] ?? null;
        self::assertIsArray($probes);
        foreach ($probes as $probe) {
            if (is_array($probe) && ($probe['slotId'] ?? null) === $slot) {
                return $this->stringKeyedArray($probe);
            }
        }
        self::fail('Runtime configuration slot probe not found.');
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $replacement
     */
    private function replaceProbe(array &$batch, array $replacement): void
    {
        $probes = $batch['slotProbes'] ?? null;
        self::assertIsArray($probes);
        foreach ($probes as $index => $probe) {
            if (is_array($probe) && ($probe['slotId'] ?? null) === $replacement['slotId']) {
                $probes[$index] = $replacement;
                $batch['slotProbes'] = $probes;
                return;
            }
        }
        self::fail('Runtime configuration probe replacement target not found.');
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function failure(array $batch, string $kind): array
    {
        $failures = $batch['failureScenarios'] ?? null;
        self::assertIsArray($failures);
        foreach ($failures as $failure) {
            if (is_array($failure) && ($failure['kind'] ?? null) === $kind) {
                return $this->stringKeyedArray($failure);
            }
        }
        self::fail('Runtime configuration failure scenario not found.');
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $replacement
     */
    private function replaceFailure(array &$batch, array $replacement): void
    {
        $failures = $batch['failureScenarios'] ?? null;
        self::assertIsArray($failures);
        foreach ($failures as $index => $failure) {
            if (is_array($failure) && ($failure['kind'] ?? null) === $replacement['kind']) {
                $failures[$index] = $replacement;
                $batch['failureScenarios'] = $failures;
                return;
            }
        }
        self::fail('Runtime configuration failure replacement target not found.');
    }

    /**
     * @return list<string>
     */
    private function blockingCodes(string $stdout): array
    {
        $codes = $this->decodeObject($stdout)['blockingCodes'] ?? null;
        self::assertIsArray($codes);
        self::assertTrue(array_is_list($codes));
        foreach ($codes as $code) {
            self::assertIsString($code);
        }
        /** @var list<string> $codes */
        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));
        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json): array
    {
        return $this->stringKeyedArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $batch
     * @return array{int, string, string}
     */
    private function runVerifier(array $batch): array
    {
        $path = $this->temporary.'/batch.json';
        self::assertIsInt(file_put_contents($path, json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        return $this->runCommand([
            PHP_BINARY, dirname(__DIR__, 2).'/tools/verify_thp_runtime_configuration_probes.php', $path, $this->candidate, $this->temporary,
        ]);
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private function runCommand(array $command): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    }
}
