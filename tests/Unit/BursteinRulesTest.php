<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Engine\Burstein\BursteinRules;
use PHPUnit\Framework\TestCase;

final class BursteinRulesTest extends TestCase
{
    public function testSameAbsoluteColourPreferenceIsAlwaysForbidden(): void
    {
        $left = $this->player(1, [Colour::Black, Colour::Black]);
        $right = $this->player(2, [Colour::Black, Colour::Black]);

        self::assertFalse((new BursteinRules())->canMeet($left, $right));
    }

    public function testRepeatOpponentIsForbidden(): void
    {
        $left = $this->player(1, [], [2]);
        $right = $this->player(2);

        self::assertFalse((new BursteinRules())->canMeet($left, $right));
    }

    public function testCompatiblePlayersCanMeet(): void
    {
        $left = $this->player(1, [Colour::White]);
        $right = $this->player(2, [Colour::White]);

        self::assertTrue((new BursteinRules())->canMeet($left, $right));
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
}
