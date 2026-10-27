<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchFloatClassifier;
use PHPUnit\Framework\TestCase;

final class HistoricalFloatClassifierTest extends TestCase
{
    public function testPairingAllocatedByeCountsAsPreviousDownfloat(): void
    {
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 2, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [],
        );

        $direction = (new DutchFloatClassifier())->direction(
            $state, Score::zero(), null, 'U', false,
        );

        self::assertSame('down', $direction);
    }

    public function testZeroPointUnplayedRoundIsNotAFloat(): void
    {
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 2, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [],
        );

        self::assertSame('none', (new DutchFloatClassifier())->direction(
            $state, Score::zero(), null, 'Z', false,
        ));
    }

    public function testHalfPointByeAboveLossCountsAsDownfloat(): void
    {
        self::assertSame('down', (new DutchFloatClassifier())->direction(
            $this->state(), Score::fromDecimal('1.0'), null, 'H', false,
        ));
    }

    public function testUnplayedForfeitWinIgnoresRecordedOpponentScore(): void
    {
        self::assertSame('down', (new DutchFloatClassifier())->direction(
            $this->state(),
            Score::zero(),
            Score::fromDecimal('2.0'),
            'F',
            false,
        ));
    }

    public function testAllocationEqualToConfiguredLossIsNotAFloat(): void
    {
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 2, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [],
            pointsForLoss: Score::fromDecimal('0.5'),
        );

        self::assertSame('none', (new DutchFloatClassifier())->direction(
            $state, Score::zero(), null, 'H', false,
        ));
    }

    private function state(): TournamentState
    {
        return new TournamentState(
            TournamentState::SCHEMA_VERSION, 2, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [],
        );
    }
}
