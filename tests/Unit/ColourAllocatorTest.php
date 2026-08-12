<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\ColourAllocator;
use PHPUnit\Framework\TestCase;

final class ColourAllocatorTest extends TestCase
{
    public function testGrantsOppositePreferences(): void
    {
        $left = $this->player(1, [Colour::White]);
        $right = $this->player(2, [Colour::Black]);
        $allocation = (new ColourAllocator())->allocate($this->state([$left, $right]), $left, $right);

        self::assertSame(2, $allocation->white->id);
        self::assertSame(1, $allocation->black->id);
        self::assertSame('5.2.1', $allocation->rule);
    }

    public function testGrantsStrongerPreference(): void
    {
        $left = $this->player(1, [Colour::White]);
        $right = $this->player(2, [Colour::Black, Colour::White]);
        $allocation = (new ColourAllocator())->allocate($this->state([$left, $right]), $left, $right);

        self::assertSame(1, $allocation->black->id);
        self::assertSame('5.2.2', $allocation->rule);
    }

    public function testFallsBackToInitialColourAndTpnParity(): void
    {
        $left = $this->player(2, []);
        $right = $this->player(3, []);
        $allocation = (new ColourAllocator())->allocate($this->state([$left, $right]), $left, $right);

        self::assertSame(3, $allocation->white->id);
        self::assertSame('5.2.5', $allocation->rule);
    }

    /** @param list<Colour> $colours */
    private function player(int $id, array $colours): Player
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
            score: Score::zero(),
            colourHistory: $colours,
        );
    }

    /** @param list<Player> $players */
    private function state(array $players): TournamentState
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
            players: $players,
        );
    }
}
