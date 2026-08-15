<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Burstein\BursteinRules;
use PHPUnit\Framework\TestCase;

final class BursteinRulesTest extends TestCase
{
    public function testSameAbsoluteColourPreferenceIsAlwaysForbidden(): void
    {
        $left = $this->player(1, [Colour::Black, Colour::Black]);
        $right = $this->player(2, [Colour::Black, Colour::Black]);

        self::assertFalse((new BursteinRules())->canMeet($this->state([$left, $right]), $left, $right));
    }

    public function testRepeatOpponentIsForbidden(): void
    {
        $left = $this->player(1, [], [2]);
        $right = $this->player(2);

        self::assertFalse((new BursteinRules())->canMeet($this->state([$left, $right]), $left, $right));
    }

    public function testCompatiblePlayersCanMeet(): void
    {
        $left = $this->player(1, [Colour::White]);
        $right = $this->player(2, [Colour::White]);

        self::assertTrue((new BursteinRules())->canMeet($this->state([$left, $right]), $left, $right));
    }

    /**
     * @param list<Colour> $colours
     * @param list<int> $opponents
     */
    private function player(int $id, array $colours = [], array $opponents = []): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2000,
            title: '',
            team: '',
            club: '',
            active: true,
            score: Score::zero(),
            opponentHistory: $opponents,
            colourHistory: $colours,
        );
    }

    /** @param list<Player> $players */
    private function state(array $players): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 3,
            numberOfRounds: 5,
            pairingSystem: 'fide-burstein-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
        );
    }
}
