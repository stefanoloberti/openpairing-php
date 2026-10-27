<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpCandidateIdentityVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-identity-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testExactCandidateArtifactsPass(): void
    {
        $manifest = $this->writeCandidate();
        [$exit, $stdout, $stderr] = $this->runVerifier($manifest);

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('PASS', $report['status']);
        $verifiedArtifacts = $report['verifiedArtifacts'] ?? null;
        self::assertIsArray($verifiedArtifacts);
        self::assertCount(4, $verifiedArtifacts);
        $candidateIdentitySha256 = $report['candidateIdentitySha256'] ?? null;
        self::assertIsString($candidateIdentitySha256);
        self::assertSame(64, strlen($candidateIdentitySha256));
        $boundary = $report['boundary'] ?? null;
        self::assertIsArray($boundary);
        self::assertFalse($boundary['submissionReadyDeclared'] ?? null);
    }

    public function testArtifactHashMismatchFailsClosed(): void
    {
        $manifest = $this->writeCandidate();
        file_put_contents($this->temporary.'/candidate-installer.bin', 'changed');

        [$exit, , $stderr] = $this->runVerifier($manifest);

        self::assertSame(2, $exit);
        self::assertStringContainsString('SHA-256 mismatch', $stderr);
    }

    public function testPathTraversalFailsClosed(): void
    {
        $manifest = $this->writeCandidate();
        $payload = $this->decodeObject((string) file_get_contents($manifest));
        $artifacts = $payload['artifacts'] ?? null;
        self::assertIsArray($artifacts);
        $first = $artifacts[0] ?? null;
        self::assertIsArray($first);
        $first['path'] = '../candidate-installer.bin';
        $artifacts[0] = $first;
        $payload['artifacts'] = $artifacts;
        file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$exit, , $stderr] = $this->runVerifier($manifest);

        self::assertSame(2, $exit);
        self::assertStringContainsString('unsafe path segment', $stderr);
    }

    public function testMissingPtcRoleFailsClosed(): void
    {
        $manifest = $this->writeCandidate();
        $payload = $this->decodeObject((string) file_get_contents($manifest));
        $artifacts = $payload['artifacts'] ?? null;
        self::assertIsArray($artifacts);
        $payload['artifacts'] = array_values(array_filter(
            $artifacts,
            static fn (mixed $artifact): bool => !is_array($artifact) || ($artifact['role'] ?? null) !== 'ptc',
        ));
        file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$exit, , $stderr] = $this->runVerifier($manifest);

        self::assertSame(2, $exit);
        self::assertStringContainsString('lacks required artifact roles: ptc', $stderr);
    }

    public function testIdentityHashIsStableAcrossSetOrdering(): void
    {
        $manifest = $this->writeCandidate();
        [$firstExit, $firstStdout, $firstStderr] = $this->runVerifier($manifest);
        self::assertSame(0, $firstExit, $firstStderr);
        $first = $this->decodeObject($firstStdout);

        $payload = $this->decodeObject((string) file_get_contents($manifest));
        $artifacts = $payload['artifacts'] ?? null;
        self::assertIsArray($artifacts);
        $payload['artifacts'] = array_reverse($artifacts);
        $standards = $payload['standards'] ?? null;
        self::assertIsArray($standards);
        $systems = $standards['pairingSystems'] ?? null;
        self::assertIsArray($systems);
        $standards['pairingSystems'] = array_reverse($systems);
        $payload['standards'] = $standards;
        file_put_contents($manifest, json_encode(array_reverse($payload, true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$secondExit, $secondStdout, $secondStderr] = $this->runVerifier($manifest);
        self::assertSame(0, $secondExit, $secondStderr);
        $second = $this->decodeObject($secondStdout);
        self::assertSame($first['candidateIdentitySha256'], $second['candidateIdentitySha256']);
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
            'product' => [
                'name' => 'ChessPairings',
                'version' => '1.0.0',
                'sourceCommit' => str_repeat('1', 40),
                'fideMode' => 'fide-individual',
            ],
            'package' => [
                'name' => 'openpairing/openpairing-php',
                'version' => '1.0.0',
                'sourceCommit' => str_repeat('2', 40),
                'sourceTreeSha256' => str_repeat('3', 64),
                'engineVersion' => 'fide-dutch-2026.global/1',
                'engineSha256' => str_repeat('4', 64),
            ],
            'runtime' => [
                'osProfile' => 'Windows 11 x64',
                'phpVersion' => '8.3.6',
                'arithmeticBackend' => 'gmp',
            ],
            'standards' => [
                'pairingSystems' => ['Dutch 2026', 'Burstein 2026'],
                'trfVersions' => ['TRF26'],
                'schemaVersions' => ['tournament-v1'],
            ],
            'artifacts' => $artifacts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        return $manifest;
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

    /** @return array{int, string, string} */
    private function runVerifier(string $manifest): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/verify_thp_candidate_identity.php',
            $manifest,
            $this->temporary,
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
