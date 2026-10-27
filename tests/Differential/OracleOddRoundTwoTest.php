<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Differential;

use OpenPairing\Development\Oracle\BbpOracleAdapter;
use OpenPairing\Development\Oracle\GacruxOracleAdapter;
use OpenPairing\Development\Oracle\OracleResult;
use OpenPairing\Development\Oracle\OracleStatus;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('oracle')]
final class OracleOddRoundTwoTest extends TestCase
{
    /** @return iterable<string, array{callable(string, TournamentState): OracleResult}> */
    public static function oracles(): iterable
    {
        yield 'Gacrux' => [static fn (string $root, TournamentState $state): OracleResult => (new GacruxOracleAdapter(
            getenv('OPENPAIRING_GACRUX_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'),
            $root.'/tools/upstream/gacrux',
            $root.'/tools/oracle/gacrux_bridge.py',
        ))->pair($state)];
        if (PHP_OS_FAMILY === 'Windows') {
            yield 'BBP' => [static fn (string $root, TournamentState $state): OracleResult => (new BbpOracleAdapter(
                $root.'/tools/upstream/bbp-pairings/bbpPairings.exe',
            ))->pair($state)];
        }
    }

    /** @param callable(string, TournamentState): OracleResult $oracleCall */
    #[DataProvider('oracles')]
    public function testExactOddRoundTwoParity(callable $oracleCall): void
    {
        $root = dirname(__DIR__, 2);
        $state = $this->state();
        $oracle = $oracleCall($root, $state);
        self::assertSame(OracleStatus::Valid, $oracle->status, $oracle->stderr);

        $php = (new DutchPairingEngine())->pair($state);
        self::assertSame($php->byePlayerId, $oracle->byePlayerId);
        self::assertSame(
            array_map(static fn ($table): array => ['whitePlayerId' => $table->whitePlayerId, 'blackPlayerId' => $table->blackPlayerId], $php->tables),
            $oracle->pairs,
        );
    }

    private function state(): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 2,
            numberOfRounds: 5,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: [
                $this->played(1, 4, Colour::White, '1', new Score(1, 1)),
                $this->played(2, 5, Colour::White, '1', new Score(1, 1)),
                new Player(
                    id: 3,
                    pairingNumber: 3,
                    name: 'Player 3',
                    rating: 2197,
                    title: '',
                    team: '',
                    club: '',
                    active: true,
                    score: new Score(1, 1),
                    results: [['round' => 1, 'opponentId' => null, 'colour' => null, 'result' => 'U', 'played' => false]],
                    receivedPairingAllocatedBye: true,
                    floatHistory: ['down'],
                ),
                $this->played(4, 1, Colour::Black, '0', Score::zero()),
                $this->played(5, 2, Colour::Black, '0', Score::zero()),
            ],
            tournamentName: 'Odd Round Two',
            pointsForPairingAllocatedBye: new Score(1, 1),
        );
    }

    private function played(int $id, int $opponent, Colour $colour, string $result, Score $score): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2200 - $id,
            title: '',
            team: '',
            club: '',
            active: true,
            score: $score,
            opponentHistory: [$opponent],
            colourHistory: [$colour],
            results: [['round' => 1, 'opponentId' => $opponent, 'colour' => $colour->value, 'result' => $result, 'played' => true]],
            floatHistory: ['none'],
        );
    }
}
