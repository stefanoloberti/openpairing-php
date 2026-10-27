<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Format\Json\CanonicalJson;
use OpenPairing\Tests\Fixtures\TournamentFactory;
use PHPUnit\Framework\TestCase;

final class ThpPrecommitGuardVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-guard-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testUnchangedReobservationPassesWithoutAuthorizingMutation(): void
    {
        $fixture = $this->writeFixture();

        [$exit, $stdout, $stderr] = $this->runVerifier($fixture);

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame([], $report['blockingCodes']);
        self::assertSame(hash_file('sha256', $fixture['fresh']), $report['freshCanonicalInputSha256']);
        $boundary = $this->object($report['boundary'] ?? null);
        self::assertFalse($boundary['databaseRereadPerformed']);
        self::assertFalse($boundary['suppliedObservationProvenLive']);
        self::assertFalse($boundary['databaseMutationPerformed']);
        self::assertFalse($boundary['atomicCommitAuthorized']);
        self::assertFalse($boundary['publicationAuthorized']);
    }

    public function testChangedRevisionIsHonestNoGo(): void
    {
        $fixture = $this->writeFixture();
        $observation = $this->readObject($fixture['observation']);
        $observation['tournamentRevision'] = '43';
        $this->writeJson($fixture['observation'], $observation);

        [$exit, $stdout, $stderr] = $this->runVerifier($fixture);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertContains('stale.tournament_revision', $this->list($report['blockingCodes'] ?? null));
    }

    public function testChangedCanonicalInputIsHonestNoGoEvenWithSameRevision(): void
    {
        $fixture = $this->writeFixture();
        file_put_contents($fixture['fresh'], (new CanonicalJson())->encode(TournamentFactory::initial(6)));
        $observation = $this->readObject($fixture['observation']);
        $snapshot = $this->object($observation['snapshot'] ?? null);
        $snapshot['sha256'] = hash_file('sha256', $fixture['fresh']);
        $observation['snapshot'] = $snapshot;
        $this->writeJson($fixture['observation'], $observation);

        [$exit, $stdout, $stderr] = $this->runVerifier($fixture);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertContains('stale.canonical_input', $this->list($report['blockingCodes'] ?? null));
    }

    public function testObservationForAnotherJobFailsAsInfrastructureError(): void
    {
        $fixture = $this->writeFixture();
        $observation = $this->readObject($fixture['observation']);
        $observation['jobId'] = 'job-20260816-another';
        $this->writeJson($fixture['observation'], $observation);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('jobId does not match', $stderr);
    }

    public function testCandidateIdentityDriftFailsAsInfrastructureError(): void
    {
        $fixture = $this->writeFixture();
        $observation = $this->readObject($fixture['observation']);
        $observation['candidateIdentitySha256'] = str_repeat('b', 64);
        $this->writeJson($fixture['observation'], $observation);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('candidateIdentitySha256 does not match', $stderr);
    }

    public function testFreshSnapshotHashMismatchFailsAsInfrastructureError(): void
    {
        $fixture = $this->writeFixture();
        file_put_contents($fixture['fresh'], "\n", FILE_APPEND);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Fresh snapshot SHA-256 mismatch', $stderr);
    }

    public function testObservationCannotPredateRequest(): void
    {
        $fixture = $this->writeFixture();
        $observation = $this->readObject($fixture['observation']);
        $observation['observedAtUtc'] = '2026-08-16T00:29:59Z';
        $this->writeJson($fixture['observation'], $observation);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('predates the original request', $stderr);
    }

    public function testInvalidOriginalRequestCannotReachGuard(): void
    {
        $fixture = $this->writeFixture();
        $request = $this->readObject($fixture['request']);
        unset($request['tournamentRevision']);
        $this->writeJson($fixture['request'], $request);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Original pairing request is invalid', $stderr);
    }

    /** @return array{request: string, original: string, observation: string, fresh: string} */
    private function writeFixture(): array
    {
        $codec = new CanonicalJson();
        $state = TournamentFactory::initial(4);
        $original = $this->temporary.'/original.json';
        $fresh = $this->temporary.'/fresh.json';
        $bytes = $codec->encode($state);
        file_put_contents($original, $bytes);
        file_put_contents($fresh, $bytes);
        $request = $this->temporary.'/request.json';
        $this->writeJson($request, [
            'schema' => 'openpairing.thp-pairing-request/1',
            'jobId' => 'job-20260816-000001',
            'tournamentRevision' => '42',
            'candidateIdentitySha256' => str_repeat('a', 64),
            'submittedAtUtc' => '2026-08-16T00:30:00Z',
            'snapshot' => [
                'path' => 'original.json',
                'sha256' => hash_file('sha256', $original),
                'schema' => $state->schemaVersion,
                'round' => $state->round,
                'pairingSystem' => $state->pairingSystem,
                'initialColour' => $state->topColour->value,
                'playerCount' => count($state->players),
                'eligiblePlayerCount' => count($state->activePlayers()),
            ],
        ]);
        $observation = $this->temporary.'/observation.json';
        $this->writeJson($observation, [
            'schema' => 'openpairing.thp-precommit-observation/1',
            'jobId' => 'job-20260816-000001',
            'candidateIdentitySha256' => str_repeat('a', 64),
            'observedAtUtc' => '2026-08-16T00:31:00Z',
            'tournamentRevision' => '42',
            'snapshot' => [
                'path' => 'fresh.json',
                'sha256' => hash_file('sha256', $fresh),
            ],
        ]);
        return compact('request', 'original', 'observation', 'fresh');
    }

    /** @param array{request: string, original: string, observation: string, fresh: string} $fixture
     * @return array{int, string, string}
     */
    private function runVerifier(array $fixture): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/verify_thp_precommit_guard.php',
            $fixture['request'],
            $fixture['original'],
            $fixture['observation'],
            $fixture['fresh'],
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

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function readObject(string $path): array
    {
        return $this->decodeObject((string) file_get_contents($path));
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return $this->object($decoded);
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
