<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchPreliminaryRoundMatcher;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use PHPUnit\Framework\TestCase;

final class DutchPreliminaryRoundMatcherTest extends TestCase
{
    public function testOddRoundLeavesAnEligiblePlayerFromTheLowestCompletableScoreGroup(): void
    {
        $state = $this->state([
            $this->player(1, '2.0'), $this->player(2, '2.0'),
            $this->player(3, '1.0'), $this->player(4, '1.0'),
            $this->player(5, '0.0'),
        ]);

        $result = (new DutchPreliminaryRoundMatcher())->match($state);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertSame([5], $result->unmatchedPlayerIds);
        self::assertSame(0, $result->byeScore?->compare(Score::zero()));
    }

    public function testPreviousPabForcesThePreliminaryByeIntoTheNextScoreGroup(): void
    {
        $state = $this->state([
            $this->player(1, '2.0'), $this->player(2, '2.0'),
            $this->player(3, '1.0'), $this->player(4, '1.0'),
            $this->player(5, '0.0', receivedPab: true),
        ]);

        $result = (new DutchPreliminaryRoundMatcher())->match($state);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertCount(1, $result->unmatchedPlayerIds);
        self::assertContains($result->unmatchedPlayerIds[0], [3, 4]);
        self::assertSame(0, $result->byeScore?->compare(Score::fromDecimal('1.0')));
    }

    public function testEvenRoundRequiresACompleteWholeRoundMatching(): void
    {
        $state = $this->state([
            $this->player(1, '1.0', opponents: [2, 3, 4]),
            $this->player(2, '1.0', opponents: [1, 3, 4]),
            $this->player(3, '0.0', opponents: [1, 2, 4]),
            $this->player(4, '0.0', opponents: [1, 2, 3]),
        ]);

        $result = (new DutchPreliminaryRoundMatcher())->match($state);

        self::assertSame(WeightedMatchingStatus::NoMatching, $result->status);
        self::assertSame([1, 2, 3, 4], $result->unmatchedPlayerIds);
    }

    /** @param list<Player> $players */
    private function state(array $players): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 3,
            numberOfRounds: 5,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: Score::fromDecimal('1.0'),
            pointsForDraw: Score::fromDecimal('0.5'),
            maxPlayers: 256,
            players: $players,
        );
    }

    /** @param list<int> $opponents */
    private function player(int $id, string $score, array $opponents = [], bool $receivedPab = false): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2300 - $id,
            title: '',
            team: '',
            club: '',
            active: true,
            score: Score::fromDecimal($score),
            opponentHistory: $opponents,
            receivedPairingAllocatedBye: $receivedPab,
        );
    }
}
