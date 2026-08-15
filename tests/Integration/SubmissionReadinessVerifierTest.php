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
        [$exit, $stdout, $stderr] = $this->runVerifier(dirname(__DIR__, 2).'/docs/schema/submission-readiness-0.9.json');

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertGreaterThan(0, $report['counts']['blocking']);
        self::assertGreaterThan(0, $report['counts']['evidenceFilesVerified']);
    }

    public function testAllClosedLedgerPassesWithExactEvidenceHash(): void
    {
        $evidence = dirname(__DIR__, 2).'/docs/API-STABILITY.md';
        $hash = hash_file('sha256', $evidence);
        self::assertIsString($hash);
        $manifest = $this->writeManifest('closed', $hash);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest);

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(0, $report['counts']['blocking']);
    }

    public function testClosedGateWithWrongHashIsInfrastructureError(): void
    {
        $manifest = $this->writeManifest('closed', str_repeat('0', 64));

        [$exit, , $stderr] = $this->runVerifier($manifest);

        self::assertSame(2, $exit);
        self::assertStringContainsString('evidence SHA-256 mismatch', $stderr);
    }

    public function testMissingManifestIsMachineReadableInfrastructureError(): void
    {
        [$exit, $stdout, $stderr] = $this->runVerifier($this->temporary.'/missing.json');

        self::assertSame(2, $exit);
        self::assertSame('', $stdout);
        $report = json_decode($stderr, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        /** @var array{status: string, code: string} $report */
        self::assertSame('ERROR', $report['status']);
        self::assertSame('submission_readiness.infrastructure', $report['code']);
    }

    public function testUnexpectedManifestFieldIsInfrastructureError(): void
    {
        $manifest = $this->writeManifest('open', str_repeat('0', 64));
        $payload = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload['claimedReady'] = true;
        self::assertIsInt(file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        [$exit, , $stderr] = $this->runVerifier($manifest);

        self::assertSame(2, $exit);
        self::assertStringContainsString('unexpected fields', $stderr);
    }

    public function testWindowsAbsoluteEvidencePathIsInfrastructureError(): void
    {
        $manifest = $this->writeManifest('open', str_repeat('0', 64), 'C:\\outside\\evidence.json');

        [$exit, , $stderr] = $this->runVerifier($manifest);

        self::assertSame(2, $exit);
        self::assertStringContainsString('unsafe evidence path', $stderr);
    }

    private function writeManifest(string $status, string $hash, string $evidencePath = 'docs/API-STABILITY.md'): string
    {
        $manifest = $this->temporary.'/manifest.json';
        self::assertIsInt(file_put_contents($manifest, json_encode([
            'schema' => 'openpairing.submission-readiness/1',
            'target' => 'test',
            'asOf' => '2026-08-16',
            'gates' => [[
                'id' => 'test-gate',
                'description' => 'Test gate.',
                'required' => true,
                'status' => $status,
                'evidence' => [[
                    'path' => $evidencePath,
                    'sha256' => $hash,
                ]],
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        return $manifest;
    }

    /** @return array{status: string, counts: array{blocking: int, evidenceFilesVerified: int}} */
    private function decodeReport(string $json): array
    {
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report)) {
            self::fail('Submission-readiness verifier did not return a JSON object.');
        }

        /** @var array{status: string, counts: array{blocking: int, evidenceFilesVerified: int}} $report */
        return $report;
    }

    /** @return array{int, string, string} */
    private function runVerifier(string $manifest): array
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
