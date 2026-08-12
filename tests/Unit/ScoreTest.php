<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Score\Score;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase
{
    public function testAddsAndReducesExactScores(): void
    {
        $sum = (new Score(1, 2))->add(new Score(2, 4));

        self::assertSame(1, $sum->units);
        self::assertSame(1, $sum->scale);
    }

    public function testSubtractsExactScores(): void
    {
        $difference = (new Score(3, 2))->subtract(new Score(1, 2));

        self::assertSame(1, $difference->units);
        self::assertSame(1, $difference->scale);
    }

    public function testMultipliesAndCrossReducesExactScores(): void
    {
        $product = (new Score(3, 2))->multiply(new Score(2, 3));

        self::assertSame(1, $product->units);
        self::assertSame(1, $product->scale);
    }

    public function testComparesEquivalentExactScoresWithoutFloats(): void
    {
        self::assertSame(0, (new Score(1, 2))->compare(new Score(2, 4)));
        self::assertLessThan(0, (new Score(1, 3))->compare(new Score(1, 2)));
        self::assertGreaterThan(0, (new Score(7, 10))->compare(new Score(2, 3)));
    }

    public function testParsesAndFormatsExactDecimalScores(): void
    {
        self::assertSame(['units' => 5, 'scale' => 10], Score::fromDecimal('0.5')->toArray());
        self::assertSame('-0.5', Score::fromDecimal('-0.5')->toDecimal(1));
        self::assertSame('1.0', (new Score(2, 2))->toDecimal(1));
    }
}
