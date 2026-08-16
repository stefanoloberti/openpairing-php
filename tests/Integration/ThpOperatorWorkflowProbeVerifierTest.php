<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpOperatorWorkflowProbeVerifierTest extends TestCase
{
    private string $temporary;
    private string $candidate;
    private string $candidateIdentity;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-operator-'.bin2hex(random_bytes(6));
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

    public function testCompleteEnglishOperatorWorkflowPasses(): void
    {
        [$exit, $stdout, $stderr] = $this->runVerifier($this->validBatch());

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(9, $report['stepCount']);
        self::assertSame(27, $report['englishAssertionCount']);
        self::assertSame([], $report['blockingCodes']);
        $boundary = $report['boundary'] ?? null;
        self::assertIsArray($boundary);
        self::assertFalse($boundary['operatorWorkflowExecutedByVerifier'] ?? null);
        self::assertFalse($boundary['submissionGateClosed'] ?? null);
    }

    public function testNonEnglishLabelIsNoGo(): void
    {
        $batch = $this->validBatch();
        $step = $this->step($batch, 'create-tournament');
        $step['nonEnglishLabelCount'] = 1;
        $this->replaceStep($batch, $step);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('create-tournament.non_english_labels', $this->blockingCodes($stdout));
    }

    public function testMissingHelpIsNoGo(): void
    {
        $batch = $this->validBatch();
        $step = $this->step($batch, 'enter-results');
        $step['missingHelpLinkCount'] = 1;
        $this->replaceStep($batch, $step);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('enter-results.missing_help', $this->blockingCodes($stdout));
    }

    public function testLegacyFallbackIsNoGo(): void
    {
        $batch = $this->validBatch();
        $step = $this->step($batch, 'generate-pairing');
        $step['legacyFallbackInvocationCount'] = 1;
        $this->replaceStep($batch, $step);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('generate-pairing.fallback', $this->blockingCodes($stdout));
    }

    public function testWriteStepWithoutAtomicCommitIsNoGo(): void
    {
        $batch = $this->validBatch();
        $step = $this->step($batch, 'register-players');
        $step['transactionCommitCount'] = 0;
        $this->replaceStep($batch, $step);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('register-players.transaction', $this->blockingCodes($stdout));
    }

    public function testEngineCallOutsidePairingIsNoGo(): void
    {
        $batch = $this->validBatch();
        $step = $this->step($batch, 'calculate-standings');
        $step['engineInvocationCount'] = 1;
        $this->replaceStep($batch, $step);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('calculate-standings.engine_calls', $this->blockingCodes($stdout));
    }

    public function testDiscontinuousStateChainIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $step = $this->step($batch, 'export-trf');
        $step['stateSha256Before'] = str_repeat('f', 64);
        $step['stateSha256After'] = str_repeat('f', 64);
        $this->replaceStep($batch, $step);

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('state chain is discontinuous', $stderr);
    }

    public function testUndeclaredPairingSystemIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $batch['pairingSystem'] = 'Unknown 2099';

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('undeclared pairing system', $stderr);
    }

    public function testPrivateFieldIsRejectedWithoutEmittingItsValue(): void
    {
        $batch = $this->validBatch();
        $batch['email'] = 'private-value-never-emit';

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('forbidden personal/secret field email', $stderr);
        self::assertStringNotContainsString('private-value-never-emit', $stderr);
    }

    /**
     * @return array<string, mixed>
     */
    private function validBatch(): array
    {
        $capabilities = [
            'activate-fide-mode', 'import-rating-list', 'create-tournament', 'register-players',
            'generate-pairing', 'enter-results', 'calculate-standings', 'export-trf', 'recover-workflow',
        ];
        $writeCapabilities = [
            'import-rating-list', 'create-tournament', 'register-players', 'generate-pairing', 'enter-results',
        ];
        $states = [];
        for ($i = 0; $i <= 5; ++$i) {
            $states[] = hash('sha256', 'operator-state-'.$i);
        }
        $stateIndex = 0;
        $steps = [];
        foreach ($capabilities as $index => $capability) {
            $writes = in_array($capability, $writeCapabilities, true);
            $before = $states[$stateIndex];
            if ($writes) {
                ++$stateIndex;
            }
            $after = $states[$stateIndex];
            $steps[] = [
                'stepId' => sprintf('operator-%02d-%s', $index + 1, $capability),
                'capability' => $capability,
                'uiLocale' => 'en',
                'screenContentSha256' => hash('sha256', 'screen-'.$capability),
                'helpTopicId' => 'help.'.$capability,
                'helpContentSha256' => hash('sha256', 'help-'.$capability),
                'englishAssertionCount' => 3,
                'nonEnglishLabelCount' => 0,
                'missingHelpLinkCount' => 0,
                'stateSha256Before' => $before,
                'stateSha256After' => $after,
                'engineInvocationCount' => $capability === 'generate-pairing' ? 1 : 0,
                'legacyFallbackInvocationCount' => 0,
                'manualFallbackInvocationCount' => 0,
                'transactionBeginCount' => $writes ? 1 : 0,
                'transactionCommitCount' => $writes ? 1 : 0,
                'transactionRollbackCount' => 0,
                'publicationCount' => 0,
                'outcome' => 'success',
                'diagnosticCode' => null,
            ];
        }
        return [
            'schema' => 'openpairing.thp-operator-workflow-probe-batch/1',
            'candidateIdentitySha256' => $this->candidateIdentity,
            'productSourceCommit' => str_repeat('1', 40),
            'migrationManifestSha256' => str_repeat('5', 64),
            'fideMode' => 'fide-individual',
            'pairingSystem' => 'Dutch 2026',
            'trfVersion' => 'TRF26',
            'syntheticPlayerCount' => 16,
            'roundsCompleted' => 3,
            'finalStandingsSha256' => hash('sha256', 'standings'),
            'exportArtifactSha256' => hash('sha256', 'trf-export'),
            'steps' => $steps,
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
    private function step(array $batch, string $capability): array
    {
        $steps = $batch['steps'] ?? null;
        self::assertIsArray($steps);
        foreach ($steps as $step) {
            if (is_array($step) && ($step['capability'] ?? null) === $capability) {
                return $this->stringKeyedArray($step);
            }
        }
        self::fail('Operator workflow step not found.');
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $replacement
     */
    private function replaceStep(array &$batch, array $replacement): void
    {
        $steps = $batch['steps'] ?? null;
        self::assertIsArray($steps);
        foreach ($steps as $index => $step) {
            if (is_array($step) && ($step['capability'] ?? null) === $replacement['capability']) {
                $steps[$index] = $replacement;
                $batch['steps'] = $steps;
                return;
            }
        }
        self::fail('Operator workflow replacement target not found.');
    }

    /** @return list<string> */
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
            PHP_BINARY, dirname(__DIR__, 2).'/tools/verify_thp_operator_workflow_probes.php', $path, $this->candidate, $this->temporary,
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
