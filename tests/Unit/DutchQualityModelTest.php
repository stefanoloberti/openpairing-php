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

    private function player(int $id, int $score): Player
    {
        return new Player($id, $id, 'P'.$id, 2200 - $id, '', '', '', true, Score::fromDecimal($score.'.0'));
    }
}
