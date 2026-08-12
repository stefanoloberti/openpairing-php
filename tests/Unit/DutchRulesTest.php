<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\ColourPreferenceStrength;
use OpenPairing\Engine\Dutch\DutchRules;
use PHPUnit\Framework\TestCase;

final class DutchRulesTest extends TestCase
{
    private DutchRules $rules;

    protected function setUp(): void
    {
        $this->rules = new DutchRules();
    }

    public function testComputesAllColourPreferenceStrengthsExactly(): void
    {
        $none = $this->rules->colourPreference($this->player(1, []));
        self::assertSame(ColourPreferenceStrength::None, $none->strength);
        self::assertNull($none->colour);

        $mild = $this->rules->colourPreference($this->player(1, [Colour::White, Colour::Black]));
        self::assertSame(ColourPreferenceStrength::Mild, $mild->strength);
        self::assertSame(Colour::White, $mild->colour);
        self::assertSame(0, $mild->difference);

        $strong = $this->rules->colourPreference($this->player(1, [Colour::White]));
        self::assertSame(ColourPreferenceStrength::Strong, $strong->strength);
        self::assertSame(Colour::Black, $strong->colour);

        $absolute = $this->rules->colourPreference($this->player(1, [Colour::Black, Colour::Black]));
        self::assertSame(ColourPreferenceStrength::Absolute, $absolute->strength);
        self::assertSame(Colour::White, $absolute->colour);
        self::assertSame(-2, $absolute->difference);
    }

    public function testC1RejectsARepeatOpponent(): void
    {
        $left = $this->player(1, [], [2]);
        $right = $this->player(2, []);

        self::assertFalse($this->rules->canMeet($this->state(3, 5, [$left, $right]), $left, $right));
    }

    public function testC3RejectsSameAbsolutePreferenceForNonTopscorers(): void
    {
        $left = $this->player(1, [Colour::Black, Colour::Black]);
        $right = $this->player(2, [Colour::Black, Colour::Black]);

        self::assertFalse($this->rules->canMeet($this->state(4, 5, [$left, $right]), $left, $right));
    }

    public function testC3ExceptionAppliesToTopscorersInFinalRound(): void
    {
        $left = $this->player(1, [Colour::Black, Colour::Black], score: new Score(3, 1));
        $right = $this->player(2, [Colour::Black, Colour::Black], score: new Score(3, 1));
        $state = $this->state(5, 5, [$left, $right]);

        self::assertTrue($this->rules->isTopscorer($state, $left));
        self::assertTrue($this->rules->canMeet($state, $left, $right));
    }

    public function testC2RejectsAPlayerWhoAlreadyScoredAWinWithoutPlaying(): void
    {
        $player = new Player(
            id: 1,
            pairingNumber: 1,
            name: 'One',
            rating: 2000,
            title: '',
            team: '',
            club: '',
            active: true,
            score: new Score(1, 1),
            results: [['round' => 1, 'opponentId' => null, 'colour' => null, 'result' => 'F', 'played' => false]],
        );

        self::assertFalse($this->rules->isEligibleForPairingAllocatedBye($this->state(2, 5, [$player]), $player));
    }

    /**
     * @param list<Colour> $colours
     * @param list<int> $opponents
     */
    private function player(int $id, array $colours, array $opponents = [], ?Score $score = null): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2000 - $id,
            title: '',
            team: '',
            club: '',
            active: true,
            score: $score ?? Score::zero(),
            opponentHistory: $opponents,
            colourHistory: $colours,
        );
    }

    /** @param list<Player> $players */
    private function state(int $round, int $rounds, array $players): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: $round,
            numberOfRounds: $rounds,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
        );
    }
}
