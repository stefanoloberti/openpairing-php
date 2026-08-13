<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class CliProductTest extends TestCase
{
    public function testLegacyTrfWithoutPairingSystemReturnsActionableError(): void
    {
        $root = dirname(__DIR__, 2);
        $trf = $root.'/build-cli-test-'.bin2hex(random_bytes(6)).'.trf';
        file_put_contents($trf, "012 Legacy\r\n142 5\r\n152 W\r\n");
        try {
            [$exit, , $stderr] = $this->runCli([$trf]);
        } finally {
            @unlink($trf);
        }

        self::assertSame(3, $exit);
        $error = $this->decodeObject($stderr);
        self::assertSame('trf.record_required', $error['code']);
        self::assertIsString($error['message']);
        self::assertStringContainsString('192 FIDE_DUTCH_2025', $error['message']);
    }

    public function testPairingJsonFixtureProducesValidatedCompleteResult(): void
    {
        $root = dirname(__DIR__, 2);
        [$exit, $stdout, $stderr] = $this->runCli([$root.'/tests/Fixtures/initial-3.json']);

        self::assertSame('', $stderr);
        self::assertSame(0, $exit);
        $result = $this->decodeObject($stdout);
        self::assertIsArray($result['tables']);
        self::assertCount(1, $result['tables']);
        self::assertNotNull($result['byePlayerId']);
        self::assertSame([], $result['validationViolations']);
    }

    public function testStandingsModeProducesRankedRowsAndExactTieBreakValues(): void
    {
        $root = dirname(__DIR__, 2);
        [$exit, $stdout, $stderr] = $this->runCli([
            '--standings',
            '--tie-breaks=DE/P,BH/C1/P,SB/C1',
            $root.'/tests/Fixtures/round-2-four.json',
        ]);

        self::assertSame('', $stderr);
        self::assertSame(0, $exit);
        $result = $this->decodeObject($stdout);
        self::assertSame('ok', $result['status']);
        self::assertSame('standings', $result['mode']);
        self::assertSame('PTS,DE/P,BH/C1/P,SB/C1', $result['rankOrder']);
        self::assertIsArray($result['standings']);
        self::assertCount(4, $result['standings']);
        self::assertIsArray($result['ranking']);
        self::assertArrayHasKey('evaluations', $result['ranking']);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $arguments): array
    {
        $root = dirname(__DIR__, 2);
        $command = array_merge([PHP_BINARY, $root.'/bin/openpairing.php'], $arguments);
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
        if (!is_string($stdout) || !is_string($stderr)) {
            throw new \RuntimeException('Could not read CLI process output.');
        }

        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \RuntimeException('CLI JSON output is not an object.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('CLI JSON output is not an object.');
            }
            $object[$key] = $item;
        }

        return $object;
    }
}
