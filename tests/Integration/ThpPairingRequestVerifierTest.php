<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Format\Json\CanonicalJson;
use OpenPairing\Tests\Fixtures\TournamentFactory;
use PHPUnit\Framework\TestCase;

final class ThpPairingRequestVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-request-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testCanonicalIdentityBoundRequestPassesWithoutCallingEngine(): void
    {
        [$manifest, $snapshot] = $this->writeRequest();

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $snapshot);

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('PASS', $report['status']);
        self::assertSame(hash_file('sha256', $snapshot), $report['canonicalInputSha256']);
        self::assertSame(str_repeat('a', 64), $report['candidateIdentitySha256']);
        $derived = $report['snapshot'] ?? null;
        self::assertIsArray($derived);
        self::assertSame(4, $derived['playerCount']);
        self::assertSame(4, $derived['eligiblePlayerCount']);
        $boundary = $report['boundary'] ?? null;
        self::assertIsArray($boundary);
        self::assertFalse($boundary['engineCalled'] ?? null);
        self::assertFalse($boundary['databaseMutationPerformed'] ?? null);
        self::assertFalse($boundary['publicationAuthorized'] ?? null);
    }

    public function testSnapshotHashMismatchFailsClosed(): void
    {
        [$manifest, $snapshot] = $this->writeRequest();
        file_put_contents($snapshot, "\n", FILE_APPEND);

        [$exit, , $stderr] = $this->runVerifier($manifest, $snapshot);

        self::assertSame(2, $exit);
        self::assertStringContainsString('SHA-256 mismatch', $stderr);
    }

    public function testValidButNonCanonicalSnapshotFailsClosed(): void
    {
        [$manifest, $snapshot] = $this->writeRequest();
        $decoded = $this->decodeObject((string) file_get_contents($snapshot));
        file_put_contents($snapshot, json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $request = $this->decodeObject((string) file_get_contents($manifest));
        $snapshotRecord = $request['snapshot'] ?? null;
        self::assertIsArray($snapshotRecord);
        $snapshotRecord['sha256'] = hash_file('sha256', $snapshot);
        $request['snapshot'] = $snapshotRecord;
        file_put_contents($manifest, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$exit, , $stderr] = $this->runVerifier($manifest, $snapshot);

        self::assertSame(2, $exit);
        self::assertStringContainsString('not in byte-canonical', $stderr);
    }

    public function testDerivedInitialColourMismatchFailsClosed(): void
    {
        [$manifest, $snapshot] = $this->writeRequest();
        $request = $this->decodeObject((string) file_get_contents($manifest));
        $snapshotRecord = $request['snapshot'] ?? null;
        self::assertIsArray($snapshotRecord);
        $snapshotRecord['initialColour'] = 'black';
        $request['snapshot'] = $snapshotRecord;
        file_put_contents($manifest, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$exit, , $stderr] = $this->runVerifier($manifest, $snapshot);

        self::assertSame(2, $exit);
        self::assertStringContainsString('snapshot.initialColour does not match', $stderr);
    }

    public function testMissingTournamentRevisionFailsClosed(): void
    {
        [$manifest, $snapshot] = $this->writeRequest();
        $request = $this->decodeObject((string) file_get_contents($manifest));
        unset($request['tournamentRevision']);
        file_put_contents($manifest, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$exit, , $stderr] = $this->runVerifier($manifest, $snapshot);

        self::assertSame(2, $exit);
        self::assertStringContainsString('lacks required fields: tournamentRevision', $stderr);
    }

    public function testCandidateIdentityHashIsMandatory(): void
    {
        [$manifest, $snapshot] = $this->writeRequest();
        $request = $this->decodeObject((string) file_get_contents($manifest));
        $request['candidateIdentitySha256'] = '';
        file_put_contents($manifest, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$exit, , $stderr] = $this->runVerifier($manifest, $snapshot);

        self::assertSame(2, $exit);
        self::assertStringContainsString('candidateIdentitySha256 must be', $stderr);
    }

    /** @return array{string, string} */
    private function writeRequest(): array
    {
        $snapshot = $this->temporary.'/snapshot.json';
        $state = TournamentFactory::initial(4);
        self::assertIsInt(file_put_contents($snapshot, (new CanonicalJson())->encode($state)));
        $manifest = $this->temporary.'/request.json';
        self::assertIsInt(file_put_contents($manifest, json_encode([
            'schema' => 'openpairing.thp-pairing-request/1',
            'jobId' => 'job-20260816-000001',
            'tournamentRevision' => '42',
            'candidateIdentitySha256' => str_repeat('a', 64),
            'submittedAtUtc' => '2026-08-16T00:30:00Z',
            'snapshot' => [
                'path' => 'snapshot.json',
                'sha256' => hash_file('sha256', $snapshot),
                'schema' => $state->schemaVersion,
                'round' => $state->round,
                'pairingSystem' => $state->pairingSystem,
                'initialColour' => $state->topColour->value,
                'playerCount' => count($state->players),
                'eligiblePlayerCount' => count($state->activePlayers()),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        return [$manifest, $snapshot];
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
    private function runVerifier(string $manifest, string $snapshot): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/verify_thp_pairing_request.php',
            $manifest,
            $snapshot,
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
