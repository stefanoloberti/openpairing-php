<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpTransactionProbeVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-tx-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testCompleteSuccessAndFaultInjectionBatchPasses(): void
    {
        $path = $this->writeBatch($this->validBatch());

        [$exit, $stdout, $stderr] = $this->runVerifier($path);

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(6, $report['failureProbeCount']);
        self::assertSame([], $report['blockingCodes']);
        $boundary = $this->object($report['boundary'] ?? null);
        self::assertFalse($boundary['databaseAccessPerformed']);
        self::assertFalse($boundary['probeHashesIndependentlyDerived']);
        self::assertFalse($boundary['faultInjectionPerformed']);
        self::assertFalse($boundary['databaseMutationPerformed']);
        self::assertFalse($boundary['productionMutationPerformed']);
        self::assertFalse($boundary['submissionGateClosed']);
    }

    public function testPartialSuccessWriteSetIsHonestNoGo(): void
    {
        $batch = $this->validBatch();
        $success = $this->object($batch['successProbe']);
        $stages = $this->list($success['stagesAttempted'] ?? null);
        array_pop($stages);
        $success['stagesAttempted'] = $stages;
        $batch['successProbe'] = $success;

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        self::assertContains('success.incomplete_write_set', $this->list($this->decodeObject($stdout)['blockingCodes'] ?? null));
    }

    public function testSuccessStateMismatchIsHonestNoGo(): void
    {
        $batch = $this->validBatch();
        $success = $this->object($batch['successProbe']);
        $success['stateAfterSha256'] = str_repeat('d', 64);
        $batch['successProbe'] = $success;

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        self::assertContains('success.state_after', $this->list($this->decodeObject($stdout)['blockingCodes'] ?? null));
    }

    public function testFailureStateNotRolledBackIsHonestNoGo(): void
    {
        $batch = $this->validBatch();
        $failures = $this->list($batch['failureProbes']);
        $first = $this->object($failures[0]);
        $first['stateAfterSha256'] = str_repeat('c', 64);
        $failures[0] = $first;
        $batch['failureProbes'] = $failures;

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        self::assertContains('after:games.state_not_rolled_back', $this->list($this->decodeObject($stdout)['blockingCodes'] ?? null));
    }

    public function testUnexpectedFailureCommitIsHonestNoGo(): void
    {
        $batch = $this->validBatch();
        $failures = $this->list($batch['failureProbes']);
        $first = $this->object($failures[0]);
        $first['committed'] = true;
        $first['commitCount'] = 1;
        $failures[0] = $first;
        $batch['failureProbes'] = $failures;

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(1, $exit, $stderr);
        $codes = $this->list($this->decodeObject($stdout)['blockingCodes'] ?? null);
        self::assertContains('after:games.unexpected_commit', $codes);
        self::assertContains('after:games.commit_count', $codes);
    }

    public function testMissingInjectionPointIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $failures = $this->list($batch['failureProbes']);
        array_pop($failures);
        $batch['failureProbes'] = $failures;

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('exactly one probe for every required write stage', $stderr);
    }

    public function testDuplicateInjectionPointIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $failures = $this->list($batch['failureProbes']);
        $duplicate = $this->object($failures[1]);
        $duplicate['injectionPoint'] = 'after:games';
        $duplicate['stagesAttempted'] = ['games'];
        $failures[1] = $duplicate;
        $batch['failureProbes'] = $failures;

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('Duplicate failure injection point', $stderr);
    }

    public function testNonPrefixFailureStageListIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $failures = $this->list($batch['failureProbes']);
        $second = $this->object($failures[1]);
        $second['stagesAttempted'] = ['history'];
        $failures[1] = $second;
        $batch['failureProbes'] = $failures;

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('not the exact write prefix', $stderr);
    }

    public function testPersonalDataKeyIsRejected(): void
    {
        $batch = $this->validBatch();
        $success = $this->object($batch['successProbe']);
        $success['email'] = 'not-recorded@example.invalid';
        $batch['successProbe'] = $success;

        [$exit, $stdout, $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringNotContainsString('not-recorded@example.invalid', $stdout.$stderr);
        self::assertStringContainsString('forbidden personal-data key', $stderr);
    }

    public function testMalformedIdentityIsInfrastructureError(): void
    {
        $batch = $this->validBatch();
        $batch['candidateIdentitySha256'] = 'invalid';

        [$exit, , $stderr] = $this->runVerifier($this->writeBatch($batch));

        self::assertSame(2, $exit);
        self::assertStringContainsString('candidateIdentitySha256 has an invalid format', $stderr);
    }

    /** @return array<string, mixed> */
    private function validBatch(): array
    {
        $stages = ['games', 'history', 'points', 'round-state', 'bye-resets', 'attestation'];
        $baseline = str_repeat('a', 64);
        $committed = str_repeat('b', 64);
        $job = 'job-20260816-000001';
        $failures = [];
        foreach ($stages as $index => $stage) {
            $failures[] = [
                'probeId' => sprintf('probe-failure-%02d-0001', $index),
                'jobId' => $job,
                'injectionPoint' => 'after:'.$stage,
                'revisionBefore' => '42',
                'revisionAfter' => '42',
                'stateBeforeSha256' => $baseline,
                'stateAfterSha256' => $baseline,
                'stagesAttempted' => array_slice($stages, 0, $index + 1),
                'committed' => false,
                'rollbackObserved' => true,
                'commitCount' => 0,
            ];
        }
        return [
            'schema' => 'openpairing.thp-transaction-probe-batch/1',
            'candidateIdentitySha256' => str_repeat('c', 64),
            'productSourceCommit' => str_repeat('d', 40),
            'migrationManifestSha256' => str_repeat('e', 64),
            'baselineRevision' => '42',
            'committedRevision' => '43',
            'baselineStateSha256' => $baseline,
            'expectedCommittedStateSha256' => $committed,
            'requiredWriteStages' => $stages,
            'successProbe' => [
                'probeId' => 'probe-success-000001',
                'jobId' => $job,
                'revisionBefore' => '42',
                'revisionAfter' => '43',
                'stateBeforeSha256' => $baseline,
                'stateAfterSha256' => $committed,
                'stagesAttempted' => $stages,
                'committed' => true,
                'rollbackObserved' => false,
                'commitCount' => 1,
            ],
            'failureProbes' => $failures,
        ];
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
            $root.'/tools/verify_thp_transaction_probes.php',
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
