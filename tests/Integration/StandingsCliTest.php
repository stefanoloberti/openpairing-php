<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class StandingsCliTest extends TestCase
{
    public function testExplicitDescriptorListProducesMachineReadableStandings(): void
    {
        $result = $this->runCli([
            '--standings',
            '--tie-breaks=DE/P,BH/C1/P,SB/C1,ARO/M1,TPR',
            'tests/Fixtures/round-2-four.json',
        ]);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $json = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('ok', $json['status']);
        self::assertSame('standings', $json['mode']);
        self::assertSame('PTS,DE/P,BH/C1/P,SB/C1,ARO/M1,TPR', $json['rankOrder']);
        self::assertIsArray($json['standings']);
        self::assertCount(4, $json['standings']);
    }

    public function testMalformedDescriptorListFailsClosed(): void
    {
        $result = $this->runCli([
            '--standings',
            '--tie-breaks=BH,C1',
            'tests/Fixtures/round-2-four.json',
        ]);

        self::assertSame(3, $result['exitCode']);
        $json = json_decode($result['stderr'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('tiebreak.descriptor_invalid', $json['code']);
    }

    /**
     * @param list<string> $arguments
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runCli(array $arguments): array
    {
        $executable = PHP_BINARY;
        $root = dirname(__DIR__, 2);
        $command = [$executable, $root.'/bin/openpairing.php', ...$arguments];
        $pipes = [];
        $process = proc_open($command, [
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
        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
    }
}
