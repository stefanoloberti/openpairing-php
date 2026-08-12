<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchQualityModel;
use PHPUnit\Framework\TestCase;

final class DutchQualityModelTest extends TestCase
{
    public function testHeVectorCountsOnlyMovedDownPlayersStillInCurrentBracket(): void
    {
        $players = [
            $this->player(1, 3), // already frozen: deliberately absent from BSN
            $this->player(2, 2),
            $this->player(3, 1),
            $this->player(4, 1),
        ];
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 4, 4, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, $players,
        );
        $quality = (new DutchQualityModel())->edge(
            $state,
            $players[1],
            $players[2],
            0,
            null,
            [1 => 2, 2 => 1, 3 => 0, 4 => 0],
            [$players[1], $players[2], $players[3]],
            [2 => 1, 3 => 2, 4 => 3],
        );

        self::assertSame([0], $quality->components['HE1']);
        self::assertSame([1], $quality->components['HE2']);
    }

    public function testHeRecordsResidentOutsideBracketInHe1NotHe2(): void
    {
        $mdp = $this->player(1, 2);
        $residentBelow = $this->player(2, 0);
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 3, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [$mdp, $residentBelow],
        );

        $quality = (new DutchQualityModel())->edge(
            $state, $mdp, $residentBelow, 1, null, [1 => 2, 2 => 0],
            [$mdp, $residentBelow], [1 => 1],
        );

        self::assertSame([1], $quality->components['HE1']);
        self::assertSame([0], $quality->components['HE2']);
    }

    public function testHe2PreservesNegativeResidentOffset(): void
    {
        $mdp1 = $this->player(1, 2);
        $mdp2 = $this->player(2, 2);
        $resident = $this->player(3, 1);
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 3, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [$mdp1, $mdp2, $resident],
        );

        $quality = (new DutchQualityModel())->edge(
            $state, $mdp1, $resident, 1, null, [1 => 2, 2 => 2, 3 => 1],
            [$mdp1, $mdp2, $resident], [1 => 1, 2 => 2, 3 => 1],
        );

        self::assertSame([-1, 0], $quality->components['HE2']);
    }

    public function testPabComplementKeepsRepeatedDownfloatersMatched(): void
    {
        $repeat = new Player(1, 1, 'Repeat', 2200, '', '', '', true, Score::zero(), floatHistory: ['down', 'down']);
        $fresh = $this->player(2, 0);
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 3, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [$repeat, $fresh],
        );

        $quality = (new DutchQualityModel())->edge(
            $state, $repeat, $fresh, 0, 0, [1 => 0, 2 => 0], [$repeat, $fresh],
        );

        self::assertSame(-1, $quality->components['QC14']);
        self::assertSame(-1, $quality->components['QC16']);
    }

    public function testResidentDownfloatDoesNotCreateScorePositionPenalty(): void
    {
        $resident = new Player(1, 1, 'Resident', 2200, '', '', '', true, Score::fromDecimal('1.0'), floatHistory: ['down', 'down']);
        $lower = $this->player(2, 0);
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 3, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [$resident, $lower],
        );

        $quality = (new DutchQualityModel())->edge(
            $state, $resident, $lower, 1, null, [1 => 1, 2 => 0], [$resident, $lower],
        );

        self::assertSame(1, $quality->components['QC14']);
        self::assertSame(1, $quality->components['QC16']);
        self::assertSame([], $quality->components['QC18']);
        self::assertSame([], $quality->components['QC20']);
    }

    private function player(int $id, int $score): Player
    {
        return new Player($id, $id, 'P'.$id, 2200 - $id, '', '', '', true, Score::fromDecimal($score.'.0'));
    }
}
