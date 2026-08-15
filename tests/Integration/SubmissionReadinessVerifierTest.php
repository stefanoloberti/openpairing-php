<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SubmissionReadinessVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-readiness-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testRepositoryLedgerIsTruthfullyNoGo(): void
    {
        [$exit, $stdout, $stderr] = $this->run(dirname(__DIR__, 2).'/docs/schema/submission-readiness-0.9.json');

        self::assertSame(1, $exit, $stderr);
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('NO_GO', $report['status']);
        self::assertGreaterThan(0, $report['counts']['blocking']);
        self::assertGreaterThan(0, $report['counts']['evidenceFilesVerified']);
    }

    public function testAllClosedLedgerPassesWithExactEvidenceHash(): void
    {
        $evidence = dirname(__DIR__, 2).'/docs/API-STABILITY.md';
        $manifest = $this->writeManifest('closed', hash_file('sha256', $evidence));

        [$exit, $stdout, $stderr] = $this->run($manifest);

        self::assertSame(0, $exit, $stderr);
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('GO', $report['status']);
        self::assertSame(0, $report['counts']['blocking']);
    }

    public function testClosedGateWithWrongHashIsInfrastructureError(): void
    {
        $manifest = $this->writeManifest('closed', str_repeat('0', 64));

        [$exit, , $stderr] = $this->run($manifest);

        self::assertSame(2, $exit);
        self::assertStringContainsString('evidence SHA-256 mismatch', $stderr);
    }

    private function writeManifest(string $status, string $hash): string
    {
        $manifest = $this->temporary.'/manifest.json';
        self::assertIsInt(file_put_contents($manifest, json_encode([
            'schema' => 'openpairing.submission-readiness/1',
            'target' => 'test',
            'gates' => [[
                'id' => 'test-gate',
                'required' => true,
                'status' => $status,
                'evidence' => [[
                    'path' => 'docs/API-STABILITY.md',
                    'sha256' => $hash,
                ]],
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        return $manifest;
    }

    /** @return array{int, string, string} */
    private function run(string $manifest): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/verify_submission_readiness.php',
            $manifest,
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
