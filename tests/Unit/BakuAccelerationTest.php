<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Acceleration\BakuAcceleration;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BakuAccelerationTest extends TestCase
{
    #[DataProvider('roundSchedule')]
    public function testNineRoundScheduleUsesFullThenHalfThenNoVirtualPoints(int $round, ?Score $expected): void
    {
        $accelerated = (new BakuAcceleration())->apply($this->state($round));

        foreach ($accelerated->players as $index => $player) {
            $actual = $player->acceleration;
            if ($index < 6 && $expected !== null) {
                self::assertNotNull($actual);
                self::assertSame(0, $actual->compare($expected));
            } else {
                self::assertNull($actual);
            }
        }
    }

    /** @return iterable<string, array{int, Score|null}> */
    public static function roundSchedule(): iterable
    {
        yield 'first full' => [1, new Score(2, 1)];
        yield 'last full' => [3, new Score(2, 1)];
        yield 'first half' => [4, new Score(1, 1)];
        yield 'last half' => [5, new Score(1, 1)];
        yield 'expired' => [6, null];
    }

    public function testPreservedLastGaPlayerSupportsLateEntries(): void
    {
        $state = $this->state(2);
        $accelerated = (new BakuAcceleration())->apply($state, 4);

        self::assertNotNull($accelerated->players[3]->acceleration);
        self::assertNull($accelerated->players[4]->acceleration);
    }

    public function testRejectsScoringThatIsNotWinEqualsTwoDrawsAndZeroLoss(): void
    {
        $state = $this->state(1, new Score(3, 1), new Score(1, 1));
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('requires win points equal to two draws');

        (new BakuAcceleration())->apply($state);
    }

    private function state(int $round, ?Score $win = null, ?Score $draw = null): TournamentState
    {
        $players = [];
        for ($id = 1; $id <= 9; ++$id) {
            $players[] = new Player(
                id: $id,
                pairingNumber: $id,
                name: 'Player '.$id,
                rating: 2300 - $id,
                title: '',
                team: '',
                club: '',
                active: true,
                score: Score::zero(),
            );
        }

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: $round,
            numberOfRounds: 9,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: $win ?? new Score(2, 1),
            pointsForDraw: $draw ?? new Score(1, 1),
            maxPlayers: 256,
            players: $players,
        );
    }
}
