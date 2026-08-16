<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpPerformanceProbeVerifierTest extends TestCase
{
    private string $temporary;
    private string $candidate;
    private string $candidateIdentity;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-performance-'.bin2hex(random_bytes(6));
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

    public function testCompleteProductPerformanceMatrixPasses(): void
    {
        [$exit, $stdout, $stderr] = $this->runVerifier($this->validBatch());

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(6, $report['probeCount']);
        self::assertSame(18, $report['sampleCount']);
        self::assertSame(950, $report['observedMaximumTurnElapsedMs']);
        self::assertSame(67_108_864, $report['observedMaximumPeakMemoryBytes']);
        self::assertSame([], $report['blockingCodes']);
        $boundary = $report['boundary'] ?? null;
        self::assertIsArray($boundary);
        self::assertFalse($boundary['productPathMeasuredByVerifier'] ?? null);
        self::assertFalse($boundary['submissionGateClosed'] ?? null);
    }

    public function testElapsedBudgetOverrunIsNoGo(): void
    {
        $batch = $this->validBatch();
        $probe = $this->probe($batch, 'Dutch 2026', 150);
        $samples = $this->samples($probe);
        $samples[2]['totalElapsedMs'] = 1000.001;
        $probe['samples'] = $samples;
        $this->replaceProbe($batch, $probe);

        [$exit, $stdout, $stderr] = $this->runVerifier($batch);

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('elapsed_budget', implode(' ', $this->blockingCodes($stdout)));
    }

    public function testMemoryBudgetOverrunIsNoGo(): void
    {
        $batch = $this->validBatch();
        $probe = $this->probe($batch, 'Burstein 2026', 80);
        $samples = $this->samples($probe);
        $samples[0]['peakMemoryBytes'] = 134_217_729;
        $probe['samples'] = $samples;
        $this->replaceProbe($batch, $probe);

        [$exit, $stdout, $stderr] = $this->runVerifier($batch);

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('memory_budget', implode(' ', $this->blockingCodes($stdout)));
    }

    public function testNonDeterministicFunctionalOutputIsNoGo(): void
    {
        $batch = $this->validBatch();
        $probe = $this->probe($batch, 'Dutch 2026', 80);
        $samples = $this->samples($probe);
        $samples[1]['functionalOutputSha256'] = str_repeat('9', 64);
        $probe['samples'] = $samples;
        $this->replaceProbe($batch, $probe);

        [$exit, $stdout, $stderr] = $this->runVerifier($batch);

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('non_deterministic_output', implode(' ', $this->blockingCodes($stdout)));
    }

    public function testUnexpectedWriteIsNoGo(): void
    {
        $batch = $this->validBatch();
        $probe = $this->probe($batch, 'Dutch 2026', 30);
        $probe['writeCount'] = 1;
        $this->replaceProbe($batch, $probe);

        [$exit, $stdout, $stderr] = $this->runVerifier($batch);

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('unexpected_write', implode(' ', $this->blockingCodes($stdout)));
    }

    public function testMissingMatrixProbeIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $probes = $batch['probes'] ?? null;
        self::assertIsArray($probes);
        array_pop($probes);
        $batch['probes'] = $probes;

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('combination', $stderr);
    }

    public function testSampleDenominatorMismatchIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $probe = $this->probe($batch, 'Dutch 2026', 30);
        $samples = $this->samples($probe);
        array_pop($samples);
        $probe['samples'] = $samples;
        $this->replaceProbe($batch, $probe);

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('3..20 samples', $stderr);
    }

    public function testBackendDriftIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $batch['arithmeticBackend'] = 'limbs-30';

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('backend differs', $stderr);
    }

    public function testPrivateFieldIsRejectedWithoutEmittingItsValue(): void
    {
        $batch = $this->validBatch();
        $batch['token'] = 'private-value-never-emit';

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('forbidden personal/secret field token', $stderr);
        self::assertStringNotContainsString('private-value-never-emit', $stderr);
    }

    /** @return array<string, mixed> */
    private function validBatch(): array
    {
        $probes = [];
        foreach (['Dutch 2026', 'Burstein 2026'] as $systemIndex => $system) {
            foreach ([30, 80, 150] as $sizeIndex => $players) {
                $hash = hash('sha256', $system.'|'.$players);
                $samples = [];
                foreach ([900.0, 925.0, 950.0] as $sampleIndex => $elapsed) {
                    $samples[] = [
                        'sampleId' => sprintf('sample-%d-%d-%d', $systemIndex, $sizeIndex, $sampleIndex),
                        'totalElapsedMs' => $elapsed,
                        'peakMemoryBytes' => 67_108_864,
                        'functionalOutputSha256' => $hash,
                        'status' => 'success',
                    ];
                }
                $probes[] = [
                    'probeId' => sprintf('performance-%d-%d-probe', $systemIndex, $players),
                    'candidateIdentitySha256' => $this->candidateIdentity,
                    'system' => $system,
                    'players' => $players,
                    'round' => $players === 30 ? 7 : 9,
                    'repetitions' => 3,
                    'writeCount' => 0,
                    'samples' => $samples,
                ];
            }
        }
        return [
            'schema' => 'openpairing.thp-performance-probe-batch/1',
            'candidateIdentitySha256' => $this->candidateIdentity,
            'productSourceCommit' => str_repeat('1', 40),
            'migrationManifestSha256' => str_repeat('5', 64),
            'arithmeticBackend' => 'gmp',
            'supportedPlayerRange' => ['minimum' => 2, 'maximum' => 150],
            'requiredPlayerCounts' => [30, 80, 150],
            'budgets' => ['maximumTurnElapsedMs' => 1000.0, 'maximumPeakMemoryBytes' => 134_217_728],
            'probes' => $probes,
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
            'standards' => ['pairingSystems' => ['Dutch 2026', 'Burstein 2026'], 'trfVersions' => ['TRF26'], 'schemaVersions' => ['tournament-v1']],
            'artifacts' => $artifacts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        return $manifest;
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function probe(array $batch, string $system, int $players): array
    {
        $probes = $batch['probes'] ?? null;
        self::assertIsArray($probes);
        foreach ($probes as $probe) {
            if (is_array($probe) && ($probe['system'] ?? null) === $system && ($probe['players'] ?? null) === $players) {
                return $this->stringKeyedArray($probe);
            }
        }
        self::fail('Performance probe not found.');
    }

    /**
     * @param array<string, mixed> $probe
     * @return list<array<string, mixed>>
     */
    private function samples(array $probe): array
    {
        $samples = $probe['samples'] ?? null;
        self::assertIsArray($samples);
        self::assertTrue(array_is_list($samples));
        $result = [];
        foreach ($samples as $sample) {
            $result[] = $this->stringKeyedArray($sample);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $replacement
     */
    private function replaceProbe(array &$batch, array $replacement): void
    {
        $probes = $batch['probes'] ?? null;
        self::assertIsArray($probes);
        foreach ($probes as $index => $probe) {
            if (is_array($probe) && ($probe['probeId'] ?? null) === $replacement['probeId']) {
                $probes[$index] = $replacement;
                $batch['probes'] = $probes;
                return;
            }
        }
        self::fail('Performance probe replacement target not found.');
    }

    /** @return list<string> */
    private function blockingCodes(string $stdout): array
    {
        $value = $this->decodeObject($stdout)['blockingCodes'] ?? null;
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

    /** @return array<string, mixed> */
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
            PHP_BINARY, dirname(__DIR__, 2).'/tools/verify_thp_performance_probes.php', $path, $this->candidate, $this->temporary,
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
