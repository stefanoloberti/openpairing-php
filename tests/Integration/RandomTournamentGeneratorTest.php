<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Format\Trf\Trf2026Codec;
use OpenPairing\Simulation\RandomTournamentGenerator;
use OpenPairing\Verification\TrfPairingChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RandomTournamentGeneratorTest extends TestCase
{
    #[DataProvider('configurations')]
    public function testGeneratedTournamentRoundTripsAndReplaysExactly(
        int $players,
        int $rounds,
        int $seed,
        string $system,
        Colour $topColour,
        bool $baku,
    ): void {
        $generator = new RandomTournamentGenerator();
        $codec = new Trf2026Codec();
        $trf = $codec->encode($generator->generate($players, $rounds, $seed, $system, $topColour, 300, $baku));
        $decoded = $codec->decode($trf);
        $report = (new TrfPairingChecker())->check($trf);

        self::assertCount($players, $decoded->players);
        self::assertSame($rounds + 1, $decoded->round);
        self::assertSame($topColour, $decoded->topColour);
        self::assertSame('exact', $report['status']);
        self::assertSame($rounds, $report['counts']['roundsExact']);
        self::assertSame('exact', $report['tieBreaks']['status']);
        if ($baku) {
            self::assertNotSame([], $decoded->players[0]->accelerationSchedule);
        }
    }

    /** @return iterable<string, array{int, int, int, string, Colour, bool}> */
    public static function configurations(): iterable
    {
        yield 'Dutch odd and black top colour' => [9, 5, 73, 'fide-dutch-2026', Colour::Black, false];
        yield 'Dutch Baku' => [9, 5, 74, 'fide-dutch-2026', Colour::White, true];
        yield 'Burstein' => [8, 4, 75, 'fide-burstein-2026', Colour::White, false];
    }

    public function testSeedIsReproducibleAndChangesResults(): void
    {
        $generator = new RandomTournamentGenerator();
        $codec = new Trf2026Codec();
        $first = $codec->encode($generator->generate(8, 4, 101));
        $again = $codec->encode($generator->generate(8, 4, 101));
        $different = $codec->encode($generator->generate(8, 4, 102));

        self::assertSame($first, $again);
        self::assertNotSame($first, $different);
    }

    public function testInvalidRoundCountFailsClosed(): void
    {
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('players - 1');
        (new RandomTournamentGenerator())->generate(8, 8, 1);
    }

    public function testPublicCliProducesTrfAndRejectsUnknownArguments(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli(['--players=8', '--rounds=4', '--seed=42', '--baku']);
        self::assertSame(0, $exit, $stderr);
        self::assertSame('', $stderr);
        self::assertSame('exact', (new TrfPairingChecker())->check($stdout)['status']);

        [$invalidExit, $invalidStdout, $invalidStderr] = $this->runCli(['--players=8', '--rounds=4', '--seed=42', 'stray']);
        self::assertSame(3, $invalidExit);
        self::assertSame('', $invalidStdout);
        $error = json_decode($invalidStderr, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertSame('rtg.arguments', $error['code']);
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $arguments): array
    {
        $root = dirname(__DIR__, 2);
        $pipes = [];
        $process = proc_open([PHP_BINARY, $root.'/bin/openpairing-rtg.php', ...$arguments], [
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
            throw new \RuntimeException('Could not read RTG CLI output.');
        }

        return [proc_close($process), $stdout, $stderr];
    }
}
