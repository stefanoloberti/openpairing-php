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

    public function testFallsBackToInitialColourAndInitialRankParity(): void
    {
        $left = $this->player(2, []);
        $right = $this->player(3, []);
        $allocation = (new ColourAllocator())->allocate($this->state([$left, $right]), $left, $right);

        self::assertSame(2, $allocation->white->id);
        self::assertSame('5.2.5', $allocation->rule);
    }

    public function testInitialRankParityDoesNotAssumeContiguousPairingNumbers(): void
    {
        $higher = $this->player(20, []);
        $opponent = $this->player(66, []);
        $before = [];
        for ($id = 1; $id < 20; ++$id) {
            $before[] = $this->player($id, []);
        }

        $allocation = (new ColourAllocator())->allocate(
            $this->state([...$before, $higher, $opponent]),
            $higher,
            $opponent,
        );

        self::assertSame(66, $allocation->white->id);
        self::assertSame(20, $allocation->black->id);
        self::assertSame('5.2.5', $allocation->rule);
    }

    public function testInactivePreviousParticipantRetainsEffectiveTpn(): void
    {
        $first = $this->player(1, []);
        $sittingOut = $this->player(2, [], active: false, results: [[
            'round' => 1,
            'opponentId' => 9,
            'colour' => 'white',
            'result' => '1',
            'played' => true,
        ]]);
        $higher = $this->player(3, []);
        $opponent = $this->player(8, []);

        $allocation = (new ColourAllocator())->allocate(
            $this->state([$first, $sittingOut, $higher, $opponent]),
            $higher,
            $opponent,
        );

        self::assertSame(3, $allocation->white->id);
        self::assertSame(8, $allocation->black->id);
        self::assertSame('5.2.5', $allocation->rule);
    }

    public function testNeverEnteredPlayerIsExcludedFromEffectiveTpn(): void
    {
        $first = $this->player(1, []);
        $neverEntered = $this->player(2, [], active: false);
        $higher = $this->player(3, []);
        $opponent = $this->player(8, []);

        $allocation = (new ColourAllocator())->allocate(
            $this->state([$first, $neverEntered, $higher, $opponent]),
            $higher,
            $opponent,
        );

        self::assertSame(8, $allocation->white->id);
        self::assertSame(3, $allocation->black->id);
        self::assertSame('5.2.5', $allocation->rule);
    }

    /**
     * @param list<Colour> $colours
     * @param list<array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool}> $results
     */
    private function player(int $id, array $colours, bool $active = true, array $results = []): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2000 - $id,
            title: '',
            team: '',
            club: '',
            active: $active,
            score: Score::zero(),
            colourHistory: $colours,
            results: $results,
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
