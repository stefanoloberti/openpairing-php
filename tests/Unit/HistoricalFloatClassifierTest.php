<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\HistoricalFloatClassifier;
use PHPUnit\Framework\TestCase;

final class HistoricalFloatClassifierTest extends TestCase
{
    public function testPairingAllocatedByeCountsAsPreviousDownfloat(): void
    {
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 2, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [],
        );

        $direction = (new HistoricalFloatClassifier())->direction(
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

        self::assertSame('none', (new HistoricalFloatClassifier())->direction(
            $state, Score::zero(), null, 'Z', false,
        ));
    }
}
