<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpRatingImportProbeVerifierTest extends TestCase
{
    private string $temporary;
    private string $candidate;
    private string $candidateIdentity;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-rating-'.bin2hex(random_bytes(6));
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

    public function testCompleteRatingImportProbeBatchPasses(): void
    {
        [$exit, $stdout, $stderr] = $this->runVerifier($this->validBatch());

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(5, $report['scenarioCount']);
        self::assertSame(10, $report['freshRowsAccepted']);
        self::assertSame([], $report['blockingCodes']);
        $boundary = $report['boundary'] ?? null;
        self::assertIsArray($boundary);
        self::assertFalse($boundary['productImportInvokedByVerifier'] ?? null);
        self::assertFalse($boundary['submissionGateClosed'] ?? null);
    }

    public function testEmptyFreshImportIsNoGo(): void
    {
        $batch = $this->validBatch();
        $fresh = $this->scenario($batch, 'fresh-import');
        foreach (['decodedRows', 'acceptedRows', 'inserted', 'updated', 'unchanged', 'deactivated', 'writeAttemptCount', 'persistedWriteCount'] as $field) {
            $fresh[$field] = 0;
        }
        $fresh['finalActive'] = $fresh['existingBefore'];
        $fresh['stateSha256After'] = $fresh['stateSha256Before'];
        $this->replaceScenario($batch, $fresh);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('fresh-import.empty_denominator', $this->blockingCodes($stdout));
    }

    public function testNonIdempotentIdenticalReimportIsNoGo(): void
    {
        $batch = $this->validBatch();
        $repeat = $this->scenario($batch, 'identical-reimport');
        $repeat['updated'] = 1;
        $repeat['unchanged'] = 9;
        $repeat['writeAttemptCount'] = 1;
        $repeat['persistedWriteCount'] = 1;
        $this->replaceScenario($batch, $repeat);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('identical-reimport.updated', $this->blockingCodes($stdout));
    }

    public function testMalformedSourceWriteIsNoGo(): void
    {
        $batch = $this->validBatch();
        $malformed = $this->scenario($batch, 'malformed-source');
        $malformed['writeAttemptCount'] = 1;
        $this->replaceScenario($batch, $malformed);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('malformed-source.writeAttemptCount', $this->blockingCodes($stdout));
    }

    public function testStalePeriodMustActuallyBeOlder(): void
    {
        $batch = $this->validBatch();
        $stale = $this->scenario($batch, 'stale-source');
        $stale['sourceListPeriod'] = '2026-08';
        $this->replaceScenario($batch, $stale);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('stale-source.period_not_older', $this->blockingCodes($stdout));
    }

    public function testFailedPersistenceMustRestoreState(): void
    {
        $batch = $this->validBatch();
        $failure = $this->scenario($batch, 'injected-db-failure');
        $failure['stateSha256After'] = str_repeat('e', 64);
        $this->replaceScenario($batch, $failure);

        [$exit, $stdout] = $this->runVerifier($batch);

        self::assertSame(1, $exit);
        self::assertContains('injected-db-failure.rollback_state_mismatch', $this->blockingCodes($stdout));
    }

    public function testMissingRequiredScenarioIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $scenarios = $batch['scenarios'] ?? null;
        self::assertIsArray($scenarios);
        array_pop($scenarios);
        $batch['scenarios'] = $scenarios;

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('exactly the five required', $stderr);
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
        $batch['token'] = 'private-value-never-emit';

        [$exit, , $stderr] = $this->runVerifier($batch);

        self::assertSame(2, $exit);
        self::assertStringContainsString('forbidden personal/secret field token', $stderr);
        self::assertStringNotContainsString('private-value-never-emit', $stderr);
    }

    /**
     * @return array<string, mixed>
     */
    private function validBatch(): array
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $common = [
            'candidateIdentitySha256' => $this->candidateIdentity,
            'sourceArtifactSha256' => str_repeat('c', 64),
            'sourceListPeriod' => '2026-08',
            'decodedRows' => 10,
            'acceptedRows' => 10,
            'rejectedRows' => 0,
            'duplicateFideIds' => 0,
            'existingBefore' => 100,
            'inserted' => 5,
            'updated' => 3,
            'unchanged' => 2,
            'deactivated' => 1,
            'finalActive' => 104,
        ];
        return [
            'schema' => 'openpairing.thp-rating-import-probe-batch/1',
            'candidateIdentitySha256' => $this->candidateIdentity,
            'productSourceCommit' => str_repeat('1', 40),
            'migrationManifestSha256' => str_repeat('5', 64),
            'requiredListPeriod' => '2026-08',
            'scenarios' => [
                array_merge($common, [
                    'scenarioId' => 'rating-fresh-import-001', 'kind' => 'fresh-import',
                    'parserDisposition' => 'accepted', 'writeAttemptCount' => 9, 'persistedWriteCount' => 9,
                    'transactionBeginCount' => 1, 'transactionCommitCount' => 1, 'transactionRollbackCount' => 0,
                    'stateSha256Before' => $a, 'stateSha256After' => $b, 'diagnosticCode' => null,
                ]),
                array_merge($common, [
                    'scenarioId' => 'rating-identical-reimport-001', 'kind' => 'identical-reimport',
                    'parserDisposition' => 'accepted', 'existingBefore' => 104, 'inserted' => 0, 'updated' => 0,
                    'unchanged' => 10, 'deactivated' => 0, 'finalActive' => 104,
                    'writeAttemptCount' => 0, 'persistedWriteCount' => 0,
                    'transactionBeginCount' => 1, 'transactionCommitCount' => 1, 'transactionRollbackCount' => 0,
                    'stateSha256Before' => $b, 'stateSha256After' => $b, 'diagnosticCode' => null,
                ]),
                $this->rejectedScenario('rating-malformed-source-001', 'malformed-source', '2026-08', 'rating_list.malformed', $b),
                $this->rejectedScenario('rating-stale-source-001', 'stale-source', '2026-07', 'rating_list.stale_period', $b),
                array_merge($common, [
                    'scenarioId' => 'rating-injected-db-failure-001', 'kind' => 'injected-db-failure',
                    'parserDisposition' => 'accepted', 'finalActive' => 100,
                    'writeAttemptCount' => 1, 'persistedWriteCount' => 0,
                    'transactionBeginCount' => 1, 'transactionCommitCount' => 0, 'transactionRollbackCount' => 1,
                    'stateSha256Before' => $a, 'stateSha256After' => $a,
                    'diagnosticCode' => 'rating_list.persistence_failure',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rejectedScenario(string $id, string $kind, string $period, string $diagnostic, string $state): array
    {
        return [
            'scenarioId' => $id, 'kind' => $kind, 'candidateIdentitySha256' => $this->candidateIdentity,
            'sourceArtifactSha256' => hash('sha256', $id), 'sourceListPeriod' => $period,
            'parserDisposition' => 'rejected', 'decodedRows' => 0, 'acceptedRows' => 0, 'rejectedRows' => 0,
            'duplicateFideIds' => 0, 'existingBefore' => 104, 'inserted' => 0, 'updated' => 0,
            'unchanged' => 0, 'deactivated' => 0, 'finalActive' => 104,
            'writeAttemptCount' => 0, 'persistedWriteCount' => 0,
            'transactionBeginCount' => 0, 'transactionCommitCount' => 0, 'transactionRollbackCount' => 0,
            'stateSha256Before' => $state, 'stateSha256After' => $state, 'diagnosticCode' => $diagnostic,
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
    private function scenario(array $batch, string $kind): array
    {
        $scenarios = $batch['scenarios'] ?? null;
        self::assertIsArray($scenarios);
        foreach ($scenarios as $scenario) {
            if (is_array($scenario) && ($scenario['kind'] ?? null) === $kind) {
                return $this->stringKeyedArray($scenario);
            }
        }
        self::fail('Rating import scenario not found.');
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
            if (is_array($scenario) && ($scenario['kind'] ?? null) === $replacement['kind']) {
                $scenarios[$index] = $replacement;
                $batch['scenarios'] = $scenarios;
                return;
            }
        }
        self::fail('Rating import scenario replacement target not found.');
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
            PHP_BINARY, dirname(__DIR__, 2).'/tools/verify_thp_rating_import_probes.php', $path, $this->candidate, $this->temporary,
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
