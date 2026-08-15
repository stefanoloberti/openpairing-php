<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class BenchmarkTurnScriptTest extends TestCase
{
    public function testSmallProductionTurnProducesAValidatedMachineReadableMeasurement(): void
    {
        $root = dirname(__DIR__, 2);
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/benchmark_turn.php',
            '--players=8',
            '--round=3',
            '--rounds=5',
            '--system=dutch',
            '--repetitions=2',
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
        self::assertSame(0, proc_close($process));
        self::assertSame('', $stderr);
        self::assertIsString($stdout);

        $report = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('openpairing.turn-benchmark/1', $report['schema']);
        self::assertSame('valid', $report['status']);
        $scenario = $report['scenario'];
        self::assertIsArray($scenario);
        self::assertSame(8, $scenario['players']);
        self::assertSame(3, $scenario['round']);
        self::assertSame(2, $scenario['repetitions']);
        $pairingMilliseconds = $report['pairingMilliseconds'];
        self::assertIsArray($pairingMilliseconds);
        $samples = $pairingMilliseconds['samples'];
        self::assertIsArray($samples);
        self::assertCount(2, $samples);
        $resultHash = $report['functionalResultSha256'];
        self::assertIsString($resultHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $resultHash);
    }
}
