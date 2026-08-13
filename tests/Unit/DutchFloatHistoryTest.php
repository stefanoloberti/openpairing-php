<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchFloatHistory;
use PHPUnit\Framework\TestCase;

final class DutchFloatHistoryTest extends TestCase
{
    public function testMissingRoundsExpireEarlierFloat(): void
    {
        $player = new Player(
            1, 1, 'Late return', 2200, '', '', '', true, Score::fromDecimal('2.0'),
            results: [
                ['round' => 1, 'opponentId' => 2, 'colour' => 'white', 'result' => '1', 'played' => true],
                ['round' => 2, 'opponentId' => 2, 'colour' => 'black', 'result' => '1', 'played' => true],
            ],
            floatHistory: ['none', 'up'],
        );
        $state = $this->state(5, $player);

        self::assertSame(0, (new DutchFloatHistory())->at($state, $player, 1, 'up'));
        self::assertSame(0, (new DutchFloatHistory())->at($state, $player, 2, 'up'));
    }

    public function testImmediatelyPreviousRoundRemainsUf1(): void
    {
        $player = new Player(
            1, 1, 'Continuous', 2200, '', '', '', true, Score::fromDecimal('2.0'),
            results: [
                ['round' => 3, 'opponentId' => 2, 'colour' => 'white', 'result' => '1', 'played' => true],
                ['round' => 4, 'opponentId' => 2, 'colour' => 'black', 'result' => '1', 'played' => true],
            ],
            floatHistory: ['none', 'up'],
        );
        $state = $this->state(5, $player);

        self::assertSame(1, (new DutchFloatHistory())->at($state, $player, 1, 'up'));
        self::assertSame(0, (new DutchFloatHistory())->at($state, $player, 2, 'up'));
    }

    private function state(int $round, Player $player): TournamentState
    {
        return new TournamentState(
            TournamentState::SCHEMA_VERSION, $round, 7, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [$player],
        );
    }
}
