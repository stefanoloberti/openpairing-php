<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\InitialDutchPairingEngine;
use PHPUnit\Framework\TestCase;

final class InitialDutchShadowColourTest extends TestCase
{
    public function testInitialSliceAlternatesTopHalfColoursByBoard(): void
    {
        $players = [];
        for ($id = 1; $id <= 7; ++$id) {
            $players[] = new Player($id, $id, 'P'.$id, 2200 - $id, '', '', '', true, Score::zero());
        }
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 1, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, $players,
        );

        $result = (new InitialDutchPairingEngine())->pair($state);

        self::assertSame(1, $result->tables[0]->whitePlayerId);
        self::assertSame(4, $result->tables[0]->blackPlayerId);
        self::assertSame(5, $result->tables[1]->whitePlayerId);
        self::assertSame(2, $result->tables[1]->blackPlayerId);
        self::assertSame(3, $result->tables[2]->whitePlayerId);
        self::assertSame(6, $result->tables[2]->blackPlayerId);
        self::assertSame(7, $result->byePlayerId);
    }
}
