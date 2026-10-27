<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Format\Json\CanonicalJson;
use OpenPairing\Tests\Fixtures\TournamentFactory;
use PHPUnit\Framework\TestCase;

final class ThpPairingResultVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-result-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporary, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                throw new \RuntimeException('Unexpected temporary directory entry.');
            }
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->temporary);
    }

    public function testIdentityBoundCompleteResultPassesWithoutMutation(): void
    {
        $fixture = $this->writeFixture();

        [$exit, $stdout, $stderr] = $this->runVerifier($fixture);

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame($fixture['candidateIdentitySha256'], $report['candidateIdentitySha256']);
        self::assertSame($fixture['canonicalInputSha256'], $report['canonicalInputSha256']);
        self::assertSame(2, $this->object($report['counts'] ?? null)['tables']);
        self::assertSame([], $report['blockingCodes']);
        $boundary = $this->object($report['boundary'] ?? null);
        self::assertFalse($boundary['engineCalled']);
        self::assertFalse($boundary['databaseMutationPerformed']);
        self::assertFalse($boundary['revisionRecheckedBeforeCommit']);
        self::assertFalse($boundary['publicationAuthorized']);
    }

    public function testCandidateIdentityDriftFailsAsInfrastructureError(): void
    {
        $fixture = $this->writeFixture();
        $envelope = $this->readObject($fixture['result']);
        $envelope['candidateIdentitySha256'] = str_repeat('f', 64);
        $this->writeJson($fixture['result'], $envelope);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('candidateIdentitySha256 does not match', $stderr);
    }

    public function testEngineIdentityDriftFailsAsInfrastructureError(): void
    {
        $fixture = $this->writeFixture();
        $envelope = $this->readObject($fixture['result']);
        $envelope['engineSha256'] = str_repeat('e', 64);
        $this->writeJson($fixture['result'], $envelope);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('engineSha256 does not match', $stderr);
    }

    public function testFunctionalHashDriftFailsAsInfrastructureError(): void
    {
        $fixture = $this->writeFixture();
        $envelope = $this->readObject($fixture['result']);
        $envelope['functionalOutputSha256'] = str_repeat('0', 64);
        $this->writeJson($fixture['result'], $envelope);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Functional output SHA-256 mismatch', $stderr);
    }

    public function testLostPlayersProduceHonestNoGo(): void
    {
        $fixture = $this->writeFixture();
        $envelope = $this->readObject($fixture['result']);
        $result = $this->object($envelope['result'] ?? null);
        $tables = $this->list($result['tables'] ?? null);
        array_pop($tables);
        $result['tables'] = $tables;
        $envelope['result'] = $result;
        $envelope['functionalOutputSha256'] = $this->functionalHash($result);
        $this->writeJson($fixture['result'], $envelope);

        [$exit, $stdout, $stderr] = $this->runVerifier($fixture);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertContains('round.player_lost', $this->list($report['blockingCodes'] ?? null));
    }

    public function testEngineViolationProducesHonestNoGo(): void
    {
        $fixture = $this->writeFixture();
        $envelope = $this->readObject($fixture['result']);
        $result = $this->object($envelope['result'] ?? null);
        $result['violations'] = [[
            'code' => 'engine.no_solution',
            'message' => 'Synthetic negative fixture.',
            'severity' => 'error',
            'context' => [],
        ]];
        $envelope['result'] = $result;
        $envelope['functionalOutputSha256'] = $this->functionalHash($result);
        $this->writeJson($fixture['result'], $envelope);

        [$exit, $stdout, $stderr] = $this->runVerifier($fixture);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertContains('engine.no_solution', $this->list($report['blockingCodes'] ?? null));
    }

    public function testDifferentTournamentRevisionCannotReuseResult(): void
    {
        $fixture = $this->writeFixture();
        $envelope = $this->readObject($fixture['result']);
        $envelope['tournamentRevision'] = '43';
        $this->writeJson($fixture['result'], $envelope);

        [$exit, , $stderr] = $this->runVerifier($fixture);

        self::assertSame(2, $exit);
        self::assertStringContainsString('tournamentRevision does not match', $stderr);
    }

    /**
     * @return array{request: string, snapshot: string, candidate: string, artifacts: string, result: string, candidateIdentitySha256: string, canonicalInputSha256: string}
     */
    private function writeFixture(): array
    {
        $root = dirname(__DIR__, 2);
        $artifacts = $this->temporary.'/artifacts';
        self::assertTrue(mkdir($artifacts));
        $artifactRecords = [];
        foreach ([
            'product-installer' => 'product.zip',
            'openpairing-runtime' => 'runtime.zip',
            'ptc' => 'ptc.php',
            'rtg' => 'rtg.php',
        ] as $role => $name) {
            $path = $artifacts.'/'.$name;
            file_put_contents($path, $role."\n");
            $artifactRecords[] = ['role' => $role, 'path' => $name, 'sha256' => hash_file('sha256', $path)];
        }

        $state = TournamentFactory::initial(4);
        $engineResult = (new DutchPairingEngine())->pair($state);
        $engineVersion = $engineResult->algorithmVersion;
        $engineSha256 = str_repeat('b', 64);
        $candidate = $this->temporary.'/candidate.json';
        $this->writeJson($candidate, [
            'schema' => 'openpairing.thp-candidate-identity/1',
            'candidateName' => 'synthetic-thp-candidate',
            'product' => [
                'name' => 'ChessPairings',
                'version' => 'test-1',
                'sourceCommit' => str_repeat('1', 40),
                'fideMode' => 'isolated-shadow',
            ],
            'package' => [
                'name' => 'openpairing/openpairing',
                'version' => 'test-1',
                'sourceCommit' => str_repeat('2', 40),
                'sourceTreeSha256' => str_repeat('a', 64),
                'engineVersion' => $engineVersion,
                'engineSha256' => $engineSha256,
            ],
            'runtime' => ['osProfile' => 'test', 'phpVersion' => PHP_VERSION, 'arithmeticBackend' => 'gmp'],
            'standards' => [
                'pairingSystems' => ['fide-dutch-2026'],
                'trfVersions' => ['TRF26'],
                'schemaVersions' => ['openpairing.tournament/1'],
            ],
            'artifacts' => $artifactRecords,
        ]);
        [$identityExit, $identityOut, $identityErr] = $this->executeProcess([
            PHP_BINARY,
            $root.'/tools/verify_thp_candidate_identity.php',
            $candidate,
            $artifacts,
        ]);
        self::assertSame(0, $identityExit, $identityErr);
        $candidateIdentitySha256 = $this->string($this->decodeObject($identityOut)['candidateIdentitySha256'] ?? null);

        $snapshot = $this->temporary.'/snapshot.json';
        self::assertIsInt(file_put_contents($snapshot, (new CanonicalJson())->encode($state)));
        $canonicalInputSha256 = hash_file('sha256', $snapshot);
        self::assertIsString($canonicalInputSha256);
        $request = $this->temporary.'/request.json';
        $this->writeJson($request, [
            'schema' => 'openpairing.thp-pairing-request/1',
            'jobId' => 'job-20260816-000001',
            'tournamentRevision' => '42',
            'candidateIdentitySha256' => $candidateIdentitySha256,
            'submittedAtUtc' => '2026-08-16T01:00:00Z',
            'snapshot' => [
                'path' => 'snapshot.json',
                'sha256' => $canonicalInputSha256,
                'schema' => $state->schemaVersion,
                'round' => $state->round,
                'pairingSystem' => $state->pairingSystem,
                'initialColour' => $state->topColour->value,
                'playerCount' => count($state->players),
                'eligiblePlayerCount' => count($state->activePlayers()),
            ],
        ]);

        $result = $engineResult->toArray();
        $resultPath = $this->temporary.'/result.json';
        $this->writeJson($resultPath, [
            'schema' => 'openpairing.thp-pairing-result/1',
            'jobId' => 'job-20260816-000001',
            'tournamentRevision' => '42',
            'candidateIdentitySha256' => $candidateIdentitySha256,
            'canonicalInputSha256' => $canonicalInputSha256,
            'completedAtUtc' => '2026-08-16T01:00:01Z',
            'engineVersion' => $engineVersion,
            'engineSha256' => $engineSha256,
            'functionalOutputSha256' => $this->functionalHash($result),
            'status' => 'success',
            'result' => $result,
        ]);

        return [
            'request' => $request,
            'snapshot' => $snapshot,
            'candidate' => $candidate,
            'artifacts' => $artifacts,
            'result' => $resultPath,
            'candidateIdentitySha256' => $candidateIdentitySha256,
            'canonicalInputSha256' => $canonicalInputSha256,
        ];
    }

    /** @param array<string, mixed> $result */
    private function functionalHash(array $result): string
    {
        $unpaired = $this->integerList($result['unpairedPlayerIds'] ?? null);
        sort($unpaired, SORT_NUMERIC);
        $floats = $this->objectList($result['floatAssignments'] ?? null);
        usort($floats, static function (array $left, array $right): int {
            $leftId = $left['playerId'] ?? null;
            $rightId = $right['playerId'] ?? null;
            if (!is_int($leftId) || !is_int($rightId)) {
                throw new \RuntimeException('Invalid synthetic float assignment.');
            }
            return $leftId <=> $rightId;
        });
        return hash('sha256', json_encode([
            'algorithmVersion' => $result['algorithmVersion'],
            'tables' => $result['tables'],
            'byePlayerId' => $result['byePlayerId'],
            'unpairedPlayerIds' => $unpaired,
            'violations' => $result['violations'],
            'qualityVector' => $result['qualityVector'],
            'floatAssignments' => $floats,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, string> $fixture
     * @return array{int, string, string}
     */
    private function runVerifier(array $fixture): array
    {
        $root = dirname(__DIR__, 2);
        return $this->executeProcess([
            PHP_BINARY,
            $root.'/tools/verify_thp_pairing_result.php',
            $fixture['request'],
            $fixture['snapshot'],
            $fixture['candidate'],
            $fixture['artifacts'],
            $fixture['result'],
        ]);
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private function executeProcess(array $command): array
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 2));
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
        self::assertIsInt(file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /** @return array<string, mixed> */
    private function readObject(string $path): array
    {
        return $this->decodeObject((string) file_get_contents($path));
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

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        $items = $this->list($value);
        $result = [];
        foreach ($items as $item) {
            if (!is_int($item)) {
                throw new \RuntimeException('Expected an integer list.');
            }
            $result[] = $item;
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function objectList(mixed $value): array
    {
        return array_map($this->object(...), $this->list($value));
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);
        return $value;
    }
}
