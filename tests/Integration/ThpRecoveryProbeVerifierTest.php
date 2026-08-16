<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpRecoveryProbeVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-recovery-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testCompleteRecoveryBatchPassesWithoutMutationClaim(): void
    {
        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($this->validBatch()));

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(4, $report['scenarioCount']);
        self::assertSame([], $report['blockingCodes']);
        $boundary = $this->object($report['boundary'] ?? null);
        self::assertFalse($boundary['databaseAccessPerformed']);
        self::assertFalse($boundary['workerRestartPerformed']);
        self::assertFalse($boundary['probeHashesIndependentlyDerived']);
        self::assertFalse($boundary['databaseMutationPerformed']);
        self::assertFalse($boundary['productionMutationPerformed']);
        self::assertFalse($boundary['submissionGateClosed']);
    }

    public function testCrashBeforeCommitStateChangeIsHonestNoGo(): void
    {
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'crash-before-commit');
        $scenario['stateAfterRecoverySha256'] = str_repeat('b', 64);
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        self::assertContains('crash-before-commit.stateAfterRecoverySha256', $this->list($this->decodeObject($stdout)['blockingCodes'] ?? null));
    }

    public function testCrashAfterCommitDuplicatePublicationIsHonestNoGo(): void
    {
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'crash-after-commit');
        $scenario['publicationCount'] = 0;
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        self::assertContains('crash-after-commit.publicationCount', $this->list($this->decodeObject($stdout)['blockingCodes'] ?? null));
    }

    public function testLateCompletionCannotCommitOverNewerRevision(): void
    {
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'late-completion');
        $scenario['commitCount'] = 1;
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        self::assertContains('late-completion.commitCount', $this->list($this->decodeObject($stdout)['blockingCodes'] ?? null));
    }

    public function testDuplicateDeliveryMustRemainIdempotent(): void
    {
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'duplicate-delivery');
        $scenario['idempotent'] = false;
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        self::assertContains('duplicate-delivery.not_idempotent', $this->list($this->decodeObject($stdout)['blockingCodes'] ?? null));
    }

    public function testMissingScenarioIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $scenarios = $this->list($batch['scenarios']);
        array_pop($scenarios);
        $batch['scenarios'] = $scenarios;

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('exactly the four required recovery cases', $stderr);
    }

    public function testDuplicateScenarioTypeIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $scenarios = $this->list($batch['scenarios']);
        $duplicate = $this->object($scenarios[1]);
        $duplicate['type'] = 'crash-before-commit';
        $scenarios[1] = $duplicate;
        $batch['scenarios'] = $scenarios;

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('Duplicate recovery scenario type', $stderr);
    }

    public function testIdentityDriftIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $batch['productSourceCommit'] = 'invalid';

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('productSourceCommit has an invalid format', $stderr);
    }

    public function testPersonalDataKeyIsRejectedWithoutValueDisclosure(): void
    {
        $sensitiveValue = 'private-person@example.invalid';
        $batch = $this->validBatch();
        $scenario = $this->scenario($batch, 'duplicate-delivery');
        $scenario['email'] = $sensitiveValue;
        $this->replaceScenario($batch, $scenario);

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringNotContainsString($sensitiveValue, $stdout.$stderr);
        self::assertStringContainsString('forbidden personal-data key', $stderr);
    }

    /** @return array<string, mixed> */
    private function validBatch(): array
    {
        $baseline = str_repeat('a', 64);
        $committed = str_repeat('b', 64);
        $newer = str_repeat('c', 64);
        return [
            'schema' => 'openpairing.thp-recovery-probe-batch/1',
            'candidateIdentitySha256' => str_repeat('d', 64),
            'productSourceCommit' => str_repeat('e', 40),
            'migrationManifestSha256' => str_repeat('f', 64),
            'baselineRevision' => '42',
            'newerRevision' => '43',
            'baselineStateSha256' => $baseline,
            'committedStateSha256' => $committed,
            'newerRevisionStateSha256' => $newer,
            'scenarios' => [
                $this->makeScenario('crash-before-commit', '42', '42', [$baseline, $baseline, $baseline, $baseline], 0, 0, 'discarded-uncommitted', 'discard-uncommitted', true),
                $this->makeScenario('crash-after-commit', '42', '42', [$baseline, $committed, $committed, $committed], 1, 1, 'already-committed', 'recognize-committed', true),
                $this->makeScenario('late-completion', '42', '43', [$newer, $newer, $newer, $newer], 0, 0, 'rejected-stale', 'reject-stale', false),
                $this->makeScenario('duplicate-delivery', '42', '42', [$baseline, $committed, $committed, $committed], 1, 1, 'deduplicated', 'deduplicate', false),
            ],
        ];
    }

    /**
     * @param list<string> $hashes
     * @return array<string, mixed>
     */
    private function makeScenario(string $type, string $requestRevision, string $currentRevision, array $hashes, int $commits, int $publications, string $disposition, string $action, bool $restart): array
    {
        return [
            'scenarioId' => 'scenario-'.$type.'-0001',
            'type' => $type,
            'jobId' => 'job-'.$type.'-0001',
            'requestRevision' => $requestRevision,
            'currentRevision' => $currentRevision,
            'stateBeforeSha256' => $hashes[0],
            'stateAfterFirstHandlingSha256' => $hashes[1],
            'stateAfterRecoverySha256' => $hashes[2],
            'stateAfterSecondRecoverySha256' => $hashes[3],
            'commitCount' => $commits,
            'publicationCount' => $publications,
            'resultDisposition' => $disposition,
            'recoveryAction' => $action,
            'workerRestartObserved' => $restart,
            'durableJobReloaded' => true,
            'idempotent' => true,
        ];
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function scenario(array $batch, string $type): array
    {
        foreach ($this->list($batch['scenarios']) as $value) {
            $scenario = $this->object($value);
            if (($scenario['type'] ?? null) === $type) {
                return $scenario;
            }
        }
        throw new \RuntimeException('Scenario fixture not found.');
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $replacement
     */
    private function replaceScenario(array &$batch, array $replacement): void
    {
        $scenarios = $this->list($batch['scenarios']);
        foreach ($scenarios as $index => $value) {
            $scenario = $this->object($value);
            if (($scenario['type'] ?? null) === ($replacement['type'] ?? null)) {
                $scenarios[$index] = $replacement;
                $batch['scenarios'] = $scenarios;
                return;
            }
        }
        throw new \RuntimeException('Scenario fixture replacement target not found.');
    }

    /** @param array<string, mixed> $batch */
    private function writeBatch(array $batch): string
    {
        $path = $this->temporary.'/batch.json';
        file_put_contents($path, json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    /** @return array{int, string, string} */
    private function runVerifier(string $path): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/verify_thp_recovery_probes.php',
            $path,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        return $this->object(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('Object key is not a string.');
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));
        return $value;
    }
}
