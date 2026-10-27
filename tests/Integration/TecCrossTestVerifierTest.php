<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class TecCrossTestVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-tec-cross-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testZeroDiscrepancyBatchPassesMechanically(): void
    {
        [$manifest, $events] = $this->writeBatch([
            $this->event('case-1', 'exact'),
            $this->event('case-2', 'exact'),
        ]);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $events, 2);

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('PASS_ZERO_DISCREPANCY', $report['status']);
        self::assertSame(2, $report['counts']['exact']);
        self::assertFalse($report['boundary']['tecAcceptanceDeclared']);
    }

    public function testInterpretationDivergenceRequiresTecReview(): void
    {
        [$manifest, $events] = $this->writeBatch([
            $this->event('case-1', 'exact'),
            $this->event('case-2', 'discrepancy', 'interpretation-divergence'),
        ]);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $events, 2);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('REVIEW_REQUIRED', $report['status']);
        self::assertSame('interpretation-divergences-require-tec-clarification', $report['review'][0]['code']);
    }

    public function testCandidateEngineErrorIsNoGo(): void
    {
        [$manifest, $events] = $this->writeBatch([
            $this->event('case-1', 'discrepancy', 'candidate-engine-error'),
        ]);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $events, 1);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertSame('candidate-engine-errors', $report['blockers'][0]['code']);
    }

    public function testMoreThanTenDiscrepanciesIsNoGo(): void
    {
        $records = [];
        for ($index = 1; $index <= 11; ++$index) {
            $records[] = $this->event('case-'.$index, 'discrepancy', 'input-error');
        }
        [$manifest, $events] = $this->writeBatch($records);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $events, 11);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertSame('discrepancy-limit-exceeded', $report['blockers'][0]['code']);
    }

    public function testDenominatorMismatchIsInfrastructureError(): void
    {
        [$manifest, $events] = $this->writeBatch([$this->event('case-1', 'exact')], 2);

        [$exit, , $stderr] = $this->runVerifier($manifest, $events, 1);

        self::assertSame(2, $exit);
        self::assertStringContainsString('denominator mismatch', $stderr);
    }

    /** @return array<string, mixed> */
    private function event(string $caseId, string $status, ?string $classification = null): array
    {
        $event = [
            'schema' => 'openpairing.tec-cross-test-case/1',
            'caseId' => $caseId,
            'inputTrfSha256' => hash('sha256', 'input-'.$caseId),
            'checkerReportSha256' => hash('sha256', 'report-'.$caseId),
            'status' => $status,
        ];
        if ($classification !== null) {
            $event['classification'] = $classification;
            $event['classificationReference'] = 'classification-sha256:'.hash('sha256', 'classification-'.$caseId);
            $event['reproductionReference'] = 'fixture-sha256:'.hash('sha256', 'fixture-'.$caseId);
        }

        return $event;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array{string, string}
     */
    private function writeBatch(array $records, ?int $attempted = null): array
    {
        $events = $this->temporary.'/cases.ndjson';
        $lines = array_map(
            static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $records,
        );
        self::assertIsInt(file_put_contents($events, implode("\n", $lines)."\n"));
        $eventsHash = hash_file('sha256', $events);
        self::assertIsString($eventsHash);
        $manifest = $this->temporary.'/batch.json';
        self::assertIsInt(file_put_contents($manifest, json_encode([
            'schema' => 'openpairing.tec-cross-test-batch/1',
            'direction' => 'external-rtg-to-candidate-ptc',
            'candidateIdentity' => [
                'productVersion' => 'candidate-1',
                'productBuildSha256' => str_repeat('1', 64),
                'packageVersion' => '1.0.0',
                'engineVersion' => 'fide-dutch-2026.global/1',
                'sourceTreeSha256' => str_repeat('2', 64),
                'runtimeArchiveSha256' => str_repeat('3', 64),
                'ptcVersion' => '1.0.0',
                'ptcSha256' => str_repeat('4', 64),
                'rtgVersion' => '1.0.0',
                'rtgSha256' => str_repeat('5', 64),
            ],
            'counterpart' => [
                'role' => 'external-rtg',
                'name' => 'TEC-provided RTG',
                'version' => 'recorded-version',
                'artifactSha256' => str_repeat('6', 64),
                'acceptanceReference' => 'TEC-provided reference',
            ],
            'attemptedTournaments' => $attempted ?? count($records),
            'eventsSha256' => $eventsHash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        return [$manifest, $events];
    }

    /** @return array{status: string, counts: array{exact: int}, blockers: list<array{code: string}>, review: list<array{code: string}>, boundary: array{tecAcceptanceDeclared: bool}} */
    private function decodeReport(string $json): array
    {
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report)) {
            self::fail('TEC cross-test verifier did not return a JSON object.');
        }

        /** @var array{status: string, counts: array{exact: int}, blockers: list<array{code: string}>, review: list<array{code: string}>, boundary: array{tecAcceptanceDeclared: bool}} $report */
        return $report;
    }

    /** @return array{int, string, string} */
    private function runVerifier(string $manifest, string $events, int $minimumTournaments): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/verify_tec_cross_test.php',
            $manifest,
            $events,
            '--minimum-tournaments='.$minimumTournaments,
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
}

