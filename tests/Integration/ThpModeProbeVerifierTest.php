<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpModeProbeVerifierTest extends TestCase
{
    private string $temporary;
    private string $candidate;
    private string $candidateIdentity;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-mode-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
        $this->candidate = $this->writeCandidate();
        [$exit, $stdout, $stderr] = $this->runCandidateVerifier();
        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        $identity = $report['candidateIdentitySha256'] ?? null;
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

    public function testCompleteIdentityBoundModeBatchPasses(): void
    {
        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($this->validBatch()));

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(6, $report['scenarioCount']);
        self::assertSame(['Dutch 2026', 'Burstein 2026'], $report['declaredPairingSystems']);
        self::assertSame([], $report['blockingCodes']);
        $boundary = $report['boundary'] ?? null;
        self::assertIsArray($boundary);
        self::assertFalse($boundary['productModeInvoked'] ?? null);
        self::assertFalse($boundary['databaseMutationPerformed'] ?? null);
        self::assertFalse($boundary['submissionGateClosed'] ?? null);
    }

    public function testCandidateIdentityDriftIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $batch['candidateIdentitySha256'] = str_repeat('9', 64);

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('not bound to the verified candidate', $stderr);
    }

    public function testMissingDeclaredSystemProbeIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $scenarios = $batch['scenarios'];
        self::assertIsArray($scenarios);
        array_splice($scenarios, 1, 1);
        $batch['scenarios'] = $scenarios;

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('four rejection cases', $stderr);
    }

    public function testUnknownSystemThatIsActuallyDeclaredIsNoGo(): void
    {
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'unknown-system');
        $scenario['requestedSystem'] = 'Dutch 2026';
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('NO_GO', $report['status']);
        $blockingCodes = $report['blockingCodes'] ?? null;
        self::assertIsArray($blockingCodes);
        self::assertContains('unknown-system.requested_system_is_declared', $blockingCodes);
    }

    public function testFallbackInvocationIsNoGo(): void
    {
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'declared-system', 'Dutch 2026');
        $scenario['legacyFallbackInvocationCount'] = 1;
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('NO_GO', $report['status']);
        $blockingCodes = $report['blockingCodes'] ?? null;
        self::assertIsArray($blockingCodes);
        self::assertStringContainsString('legacy_fallback', implode(' ', $this->stringList($blockingCodes)));
    }

    public function testTeamInputReachingEngineIsNoGo(): void
    {
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'team-input');
        $scenario['engineInvocationCount'] = 1;
        $scenario['selectedSystem'] = 'Dutch 2026';
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        self::assertSame('NO_GO', $this->decodeObject($stdout)['status']);
    }

    public function testUnsupportedRecordWithWriteIsNoGo(): void
    {
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'record-299');
        $scenario['writeCount'] = 1;
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        $blockingCodes = $report['blockingCodes'] ?? null;
        self::assertIsArray($blockingCodes);
        self::assertStringContainsString('unexpected_write', implode(' ', $this->stringList($blockingCodes)));
    }

    public function testDeclaredSystemOrderDriftIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $batch['declaredPairingSystems'] = ['Burstein 2026', 'Dutch 2026'];

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('including order', $stderr);
    }

    public function testPrivateFieldIsRejectedWithoutEmittingItsValue(): void
    {
        $batch = $this->validBatch();
        $batch['email'] = 'private-value-never-emit@example.invalid';

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('forbidden personal/secret field email', $stderr);
        self::assertStringNotContainsString('private-value-never-emit', $stderr);
    }

    /** @return array<string, mixed> */
    private function validBatch(): array
    {
        $base = [
            'candidateIdentitySha256' => $this->candidateIdentity,
            'requestedMode' => 'fide-individual',
            'legacyFallbackInvocationCount' => 0,
            'manualFallbackInvocationCount' => 0,
            'writeCount' => 0,
        ];
        $scenarios = [];
        foreach (['Dutch 2026', 'Burstein 2026'] as $index => $system) {
            $scenarios[] = $base + [
                'scenarioId' => 'declared-system-'.($index + 1).'-probe',
                'kind' => 'declared-system',
                'requestedSystem' => $system,
                'inputKind' => 'individual',
                'unsupportedMarker' => 'none',
                'engineInvocationCount' => 1,
                'selectedSystem' => $system,
                'disposition' => 'accepted',
                'diagnosticCode' => null,
            ];
        }
        $scenarios[] = $base + [
            'scenarioId' => 'unknown-system-probe', 'kind' => 'unknown-system',
            'requestedSystem' => 'unknown-system', 'inputKind' => 'individual', 'unsupportedMarker' => 'none',
            'engineInvocationCount' => 0, 'selectedSystem' => null, 'disposition' => 'rejected-unsupported',
            'diagnosticCode' => 'fide_mode.system_unsupported',
        ];
        $scenarios[] = $base + [
            'scenarioId' => 'team-input-reject-probe', 'kind' => 'team-input',
            'requestedSystem' => 'Dutch 2026', 'inputKind' => 'team', 'unsupportedMarker' => 'none',
            'engineInvocationCount' => 0, 'selectedSystem' => null, 'disposition' => 'rejected-unsupported',
            'diagnosticCode' => 'fide_mode.team_unsupported',
        ];
        $scenarios[] = $base + [
            'scenarioId' => 'record-299-reject-probe', 'kind' => 'record-299',
            'requestedSystem' => 'Dutch 2026', 'inputKind' => 'individual', 'unsupportedMarker' => 'record-299',
            'engineInvocationCount' => 0, 'selectedSystem' => null, 'disposition' => 'rejected-unsupported',
            'diagnosticCode' => 'trf.record_299_unsupported',
        ];
        $scenarios[] = $base + [
            'scenarioId' => 'undeclared-feature-probe', 'kind' => 'undeclared-feature',
            'requestedSystem' => 'Dutch 2026', 'inputKind' => 'individual', 'unsupportedMarker' => 'undeclared-feature',
            'engineInvocationCount' => 0, 'selectedSystem' => null, 'disposition' => 'rejected-unsupported',
            'diagnosticCode' => 'fide_mode.feature_unsupported',
        ];
        return [
            'schema' => 'openpairing.thp-mode-probe-batch/1',
            'candidateIdentitySha256' => $this->candidateIdentity,
            'productSourceCommit' => str_repeat('1', 40),
            'migrationManifestSha256' => str_repeat('5', 64),
            'fideMode' => 'fide-individual',
            'declaredPairingSystems' => ['Dutch 2026', 'Burstein 2026'],
            'scenarios' => $scenarios,
        ];
    }

    private function writeCandidate(): string
    {
        $files = [
            'product-installer' => 'candidate-installer.bin',
            'openpairing-runtime' => 'openpairing-runtime.zip',
            'ptc' => 'openpairing-ptc.php',
            'rtg' => 'openpairing-rtg.php',
        ];
        $artifacts = [];
        foreach ($files as $role => $path) {
            self::assertIsInt(file_put_contents($this->temporary.'/'.$path, 'artifact-'.$role));
            $artifacts[] = ['role' => $role, 'path' => $path, 'sha256' => hash_file('sha256', $this->temporary.'/'.$path)];
        }
        $manifest = $this->temporary.'/candidate.json';
        self::assertIsInt(file_put_contents($manifest, json_encode([
            'schema' => 'openpairing.thp-candidate-identity/1',
            'candidateName' => 'ChessPairings-OpenPairing-1.0.0',
            'product' => ['name' => 'ChessPairings', 'version' => '1.0.0', 'sourceCommit' => str_repeat('1', 40), 'fideMode' => 'fide-individual'],
            'package' => [
                'name' => 'openpairing/openpairing-php', 'version' => '1.0.0', 'sourceCommit' => str_repeat('2', 40),
                'sourceTreeSha256' => str_repeat('3', 64), 'engineVersion' => 'fide-dutch-2026.global/1', 'engineSha256' => str_repeat('4', 64),
            ],
            'runtime' => ['osProfile' => 'Windows 11 x64', 'phpVersion' => '8.3.6', 'arithmeticBackend' => 'gmp'],
            'standards' => ['pairingSystems' => ['Dutch 2026', 'Burstein 2026'], 'trfVersions' => ['TRF26'], 'schemaVersions' => ['tournament-v1']],
            'artifacts' => $artifacts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        return $manifest;
    }

    /** @param array<string, mixed> $batch */
    private function writeBatch(array $batch): string
    {
        $path = $this->temporary.'/batch.json';
        self::assertIsInt(file_put_contents($path, json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        return $path;
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function scenario(array $batch, string $kind, ?string $system = null): array
    {
        $scenarios = $batch['scenarios'] ?? null;
        self::assertIsArray($scenarios);
        foreach ($scenarios as $scenario) {
            if (is_array($scenario) && ($scenario['kind'] ?? null) === $kind
                && ($system === null || ($scenario['requestedSystem'] ?? null) === $system)) {
                return $this->stringKeyedArray($scenario);
            }
        }
        self::fail('Scenario not found: '.$kind);
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $replacement
     */
    private function replaceScenario(array &$batch, array $replacement): void
    {
        $scenarios = $batch['scenarios'] ?? null;
        self::assertIsArray($scenarios);
        foreach ($scenarios as $index => $scenario) {
            if (is_array($scenario) && ($scenario['scenarioId'] ?? null) === $replacement['scenarioId']) {
                $scenarios[$index] = $replacement;
                $batch['scenarios'] = $scenarios;
                return;
            }
        }
        self::fail('Scenario replacement target not found.');
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));
        $result = [];
        foreach ($value as $item) {
            self::assertIsString($item);
            $result[] = $item;
        }
        return $result;
    }

    /** @return array<string, mixed> */
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

    /** @return array{int, string, string} */
    private function runCandidateVerifier(): array
    {
        return $this->runCommand([PHP_BINARY, dirname(__DIR__, 2).'/tools/verify_thp_candidate_identity.php', $this->candidate, $this->temporary]);
    }

    /** @return array{int, string, string} */
    private function runVerifier(string $batch): array
    {
        return $this->runCommand([PHP_BINARY, dirname(__DIR__, 2).'/tools/verify_thp_mode_probes.php', $batch, $this->candidate, $this->temporary]);
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
