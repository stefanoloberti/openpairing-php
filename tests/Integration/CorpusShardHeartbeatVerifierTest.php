<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class CorpusShardHeartbeatVerifierTest extends TestCase
{
    private string $temporary;
    private const HASHES = [
        'engineSha256' => '1111111111111111111111111111111111111111111111111111111111111111',
        'sourceTreeSha256' => '2222222222222222222222222222222222222222222222222222222222222222',
        'harnessSha256' => '3333333333333333333333333333333333333333333333333333333333333333',
        'runtimeIdentityLockSha256' => '4444444444444444444444444444444444444444444444444444444444444444',
    ];

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-heartbeat-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testValidHeartbeatPassesAsNonEvidence(): void
    {
        $current = $this->write('current.json', $this->heartbeat());
        [$exit, $stdout, $stderr] = $this->verify($current);

        self::assertSame(0, $exit, $stderr);
        $report = $this->object($stdout);
        self::assertSame('PASS', $report['status']);
        self::assertFalse($report['previousCompared']);
        $boundary = $report['boundary'] ?? null;
        self::assertIsArray($boundary);
        self::assertFalse($boundary['terminalReport']);
        self::assertFalse($boundary['acceptedEvidence']);
    }

    public function testIncreasingHeartbeatPairPasses(): void
    {
        $previousPayload = $this->heartbeat();
        $currentPayload = $previousPayload;
        $currentPayload['observedAtUtc'] = '2026-08-16T11:05:00Z';
        $currentPayload['elapsedSeconds'] = 300.5;
        $currentPayload['filesCompleted'] = 2;
        $currentPayload['tournamentsVerified'] = 2;
        $currentPayload['roundsAttempted'] = 20;
        $rounds = $currentPayload['rounds'] ?? null;
        self::assertIsArray($rounds);
        $rounds['exact'] = 20;
        $currentPayload['rounds'] = $rounds;
        $currentPayload['lastCompletedInputPathSha256'] = str_repeat('b', 64);

        [$exit, $stdout, $stderr] = $this->verify(
            $this->write('current.json', $currentPayload),
            $this->write('previous.json', $previousPayload),
        );

        self::assertSame(0, $exit, $stderr);
        self::assertTrue($this->object($stdout)['previousCompared']);
    }

    public function testDecreasingCounterFailsClosed(): void
    {
        $previous = $this->heartbeat();
        $current = $previous;
        $current['observedAtUtc'] = '2026-08-16T11:05:00Z';
        $current['elapsedSeconds'] = 300.0;
        $current['roundsAttempted'] = 9;
        $rounds = $current['rounds'] ?? null;
        self::assertIsArray($rounds);
        $rounds['exact'] = 9;
        $current['rounds'] = $rounds;

        [$exit, , $stderr] = $this->verify(
            $this->write('current.json', $current),
            $this->write('previous.json', $previous),
        );

        self::assertSame(2, $exit);
        self::assertStringContainsString('counter decreased', $stderr);
    }

    public function testChangedProcessTokenFailsContinuity(): void
    {
        $previous = $this->heartbeat();
        $current = $previous;
        $current['observedAtUtc'] = '2026-08-16T11:05:00Z';
        $current['processStartToken'] = str_repeat('c', 64);

        [$exit, , $stderr] = $this->verify(
            $this->write('current.json', $current),
            $this->write('previous.json', $previous),
        );

        self::assertSame(2, $exit);
        self::assertStringContainsString('processStartToken', $stderr);
    }

    public function testIdentityKeyOrderDoesNotBreakContinuity(): void
    {
        $previous = $this->heartbeat();
        $current = $previous;
        $current['observedAtUtc'] = '2026-08-16T11:05:00Z';
        $identity = $current['identity'] ?? null;
        self::assertIsArray($identity);
        $current['identity'] = array_reverse($identity, true);

        [$exit, , $stderr] = $this->verify(
            $this->write('current.json', $current),
            $this->write('previous.json', $previous),
        );

        self::assertSame(0, $exit, $stderr);
    }

    public function testIdentityMismatchFailsClosed(): void
    {
        $heartbeat = $this->heartbeat();
        $identity = $heartbeat['identity'] ?? null;
        self::assertIsArray($identity);
        $identity['engineSha256'] = str_repeat('f', 64);
        $heartbeat['identity'] = $identity;

        [$exit, , $stderr] = $this->verify($this->write('current.json', $heartbeat));

        self::assertSame(2, $exit);
        self::assertStringContainsString('engineSha256', $stderr);
    }

    public function testRoundAccountingMismatchFailsClosed(): void
    {
        $heartbeat = $this->heartbeat();
        $rounds = $heartbeat['rounds'] ?? null;
        self::assertIsArray($rounds);
        $rounds['colourVariant'] = 1;
        $heartbeat['rounds'] = $rounds;

        [$exit, , $stderr] = $this->verify($this->write('current.json', $heartbeat));

        self::assertSame(2, $exit);
        self::assertStringContainsString('round categories', $stderr);
    }

    public function testUnexpectedPathFieldFailsPrivacyBoundary(): void
    {
        $heartbeat = $this->heartbeat();
        $heartbeat['lastCompletedInputPath'] = '/private/corpus/player.trf';

        [$exit, $stdout, $stderr] = $this->verify($this->write('current.json', $heartbeat));

        self::assertSame(2, $exit);
        self::assertStringContainsString('unexpected fields', $stderr);
        self::assertStringNotContainsString('/private/corpus/player.trf', $stdout.$stderr);
    }

    /** @return array<string, mixed> */
    private function heartbeat(): array
    {
        return [
            'schema' => 'openpairing.corpus-shard-heartbeat/1',
            'campaign' => 'p80-epoch5-test',
            'shard' => 's000',
            'processStartToken' => str_repeat('a', 64),
            'startedAtUtc' => '2026-08-16T11:00:00Z',
            'observedAtUtc' => '2026-08-16T11:02:00Z',
            'elapsedSeconds' => 120.25,
            'filesTotal' => 79,
            'filesCompleted' => 1,
            'tournamentsVerified' => 1,
            'roundsAttempted' => 10,
            'rounds' => [
                'exact' => 10,
                'colourVariant' => 0,
                'pairingDeviation' => 0,
                'noSolution' => 0,
                'resourceLimit' => 0,
            ],
            'identity' => [
                ...self::HASHES,
                'arithmeticBackend' => 'gmp',
                'gmpAvailable' => true,
                'cliJitEnabled' => true,
            ],
            'lastCompletedInputPathSha256' => str_repeat('9', 64),
            'terminal' => false,
            'acceptedEvidence' => false,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function write(string $name, array $payload): string
    {
        $path = $this->temporary.'/'.$name;
        self::assertIsInt(file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
        return $path;
    }

    /** @return array{int, string, string} */
    private function verify(string $current, ?string $previous = null): array
    {
        $root = dirname(__DIR__, 2);
        $command = [
            PHP_BINARY,
            $root.'/tools/verify_corpus_shard_heartbeat.php',
            $current,
            'p80-epoch5-test',
            's000',
            self::HASHES['engineSha256'],
            self::HASHES['sourceTreeSha256'],
            self::HASHES['harnessSha256'],
            self::HASHES['runtimeIdentityLockSha256'],
            'gmp',
        ];
        if ($previous !== null) {
            $command[] = $previous;
        }
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array<string, mixed> */
    private function object(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));
        $object = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $object[$key] = $value;
        }
        return $object;
    }
}
