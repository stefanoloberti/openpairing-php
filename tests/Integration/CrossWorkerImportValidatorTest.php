<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Diagnostics\CorpusIdentity;
use PHPUnit\Framework\TestCase;

final class CrossWorkerImportValidatorTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-cross-import-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary.'/corpus', 0777, true));
        self::assertIsInt(file_put_contents($this->temporary.'/corpus/case.trf', "012 sample\n"));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporary);
    }

    public function testValidQuarantinedReportMatchesPrimaryCorpus(): void
    {
        [$report, $attestation, $source, $harness, $commit] = $this->writeEvidence();

        [$exit, $stdout, $stderr] = $this->runValidator($report, $attestation, $source, $harness, $commit);

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('PASS_QUARANTINE_VALIDATED_NOT_YET_ACCEPTED', $stdout);
        $receipt = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($receipt);
        self::assertSame('s192', $receipt['shard']);
        $counts = $receipt['counts'] ?? null;
        self::assertIsArray($counts);
        self::assertSame(1, $counts['roundsAttempted']);
        self::assertSame(CorpusIdentity::capture($this->temporary.'/corpus', 0)['identity'], $receipt['corpusIdentity']);
    }

    public function testRawReportMutationIsRejectedBeforePrimaryClassification(): void
    {
        [$report, $attestation, $source, $harness, $commit] = $this->writeEvidence();
        self::assertIsInt(file_put_contents($report, "\n", FILE_APPEND));

        [$exit, , $stderr] = $this->runValidator($report, $attestation, $source, $harness, $commit);

        self::assertSame(4, $exit);
        self::assertStringContainsString('raw report SHA-256 mismatch', $stderr);
    }

    public function testChangedPrimaryCorpusIsRejected(): void
    {
        [$report, $attestation, $source, $harness, $commit] = $this->writeEvidence();
        self::assertIsInt(file_put_contents($this->temporary.'/corpus/case.trf', "012 changed\n"));

        [$exit, , $stderr] = $this->runValidator($report, $attestation, $source, $harness, $commit);

        self::assertSame(4, $exit);
        self::assertStringContainsString('Primary corpus identity differs', $stderr);
    }

    public function testAlteredProvenanceIdentityIsRejected(): void
    {
        [$report, $attestation, $source, $harness, $commit] = $this->writeEvidence();
        $provenance = json_decode((string) file_get_contents($attestation), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($provenance);
        $provenance['host'] = 'unexpected-host';
        self::assertIsInt(file_put_contents($attestation, json_encode($provenance, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        [$exit, , $stderr] = $this->runValidator($report, $attestation, $source, $harness, $commit);

        self::assertSame(4, $exit);
        self::assertStringContainsString('provenance identity mismatch', $stderr);
    }

    public function testNonEmptyDiagnosticsAreRejectedWithInternallyConsistentHashes(): void
    {
        [$report, $attestation, $source, $harness, $commit] = $this->writeEvidence();
        $this->rewriteReportAndAttestation(
            $report,
            $attestation,
            static function (array $data): array {
                $data['diagnostics'] = ['compatibility.error' => 1];

                return $data;
            },
        );

        [$exit, , $stderr] = $this->runValidator($report, $attestation, $source, $harness, $commit);

        self::assertSame(4, $exit);
        self::assertStringContainsString('incomplete or contains infrastructure/fatal diagnostics', $stderr);
    }

    /** @return array{string, string, string, string, string} */
    private function writeEvidence(): array
    {
        $source = str_repeat('a', 64);
        $harness = str_repeat('b', 64);
        $commit = str_repeat('c', 40);
        $runtime = [
            'sourceTreeSha256' => $source,
            'arithmeticBackend' => 'gmp',
            'projectRoot' => '/secondary/release',
            'autoloadPsr4Roots' => ['/secondary/release/src'],
            'expectedEnginePath' => '/secondary/release/src/Dutch.php',
            'loadedEnginePath' => '/secondary/release/src/Dutch.php',
        ];
        $harnessIdentity = ['sha256' => $harness];
        $corpusIdentity = CorpusIdentity::capture($this->temporary.'/corpus', 0)['identity'];
        $reportData = [
            'productionOnly' => true,
            'largeFieldAudit' => true,
            'root' => '/secondary/corpus/s192',
            'elapsedSeconds' => 1.25,
            'runtimeIdentity' => $runtime,
            'harnessIdentity' => $harnessIdentity,
            'corpusIdentity' => $corpusIdentity,
            'counts' => [
                'filesSeen' => 1,
                'decoded' => 1,
                'tournamentsVerified' => 1,
                'roundsAttempted' => 1,
                'largeField.candidateNoMatching' => 0,
                'roundsEngineNoSolution' => 0,
                'roundsEngineResourceLimit' => 0,
            ],
            'diagnostics' => [],
        ];
        $report = $this->temporary.'/report.json';
        self::assertIsInt(file_put_contents($report, json_encode($reportData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        $semantic = $reportData;
        unset(
            $semantic['root'],
            $semantic['elapsedSeconds'],
            $semantic['runtimeIdentity']['projectRoot'],
            $semantic['runtimeIdentity']['autoloadPsr4Roots'],
            $semantic['runtimeIdentity']['expectedEnginePath'],
            $semantic['runtimeIdentity']['loadedEnginePath'],
        );
        $attestationData = [
            'schema' => 'openpairing.cross-worker-provenance/1',
            'acceptance' => 'quarantine-only',
            'primaryAcceptor' => 'doppia-3090',
            'shard' => 's192',
            'host' => 'DESKTOP-EF1C0NB',
            'campaignCommit' => $commit,
            'runtimeIdentity' => $runtime,
            'harnessIdentity' => $harnessIdentity,
            'corpusIdentity' => $corpusIdentity,
            'reportSha256' => hash_file('sha256', $report),
            'canonicalSemanticSha256' => hash('sha256', json_encode($semantic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'startedAt' => '2026-08-15T23:00:00+02:00',
            'finishedAt' => '2026-08-16T00:00:00+02:00',
        ];
        $attestation = $this->temporary.'/attestation.json';
        self::assertIsInt(file_put_contents($attestation, json_encode($attestationData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        return [$report, $attestation, $source, $harness, $commit];
    }

    /** @param callable(array<mixed>): array<mixed> $mutate */
    private function rewriteReportAndAttestation(string $report, string $attestation, callable $mutate): void
    {
        $reportData = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($reportData);
        /** @var array<string, mixed> $reportData */
        $reportData = $mutate($reportData);
        self::assertIsInt(file_put_contents($report, json_encode($reportData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        $semantic = $reportData;
        unset($semantic['root'], $semantic['elapsedSeconds']);
        $runtime = $semantic['runtimeIdentity'] ?? null;
        self::assertIsArray($runtime);
        unset($runtime['projectRoot'], $runtime['autoloadPsr4Roots'], $runtime['expectedEnginePath'], $runtime['loadedEnginePath']);
        $semantic['runtimeIdentity'] = $runtime;
        $provenance = json_decode((string) file_get_contents($attestation), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($provenance);
        $provenance['reportSha256'] = hash_file('sha256', $report);
        $provenance['canonicalSemanticSha256'] = hash(
            'sha256',
            json_encode($semantic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        self::assertIsInt(file_put_contents($attestation, json_encode($provenance, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /** @return array{int, string, string} */
    private function runValidator(string $report, string $attestation, string $source, string $harness, string $commit): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/corpus/validate_cross_worker_import.php',
            $report,
            $attestation,
            $this->temporary.'/corpus',
            's192',
            'DESKTOP-EF1C0NB',
            $source,
            $harness,
            $commit,
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

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
