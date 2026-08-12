<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Engine\Dutch\GlobalMatching\DutchQualityVector;
use PHPUnit\Framework\TestCase;

final class DutchQualityVectorTest extends TestCase
{
    public function testEarlierCriterionDominatesEveryLaterCriterion(): void
    {
        $qc9Better = new DutchQualityVector(['QC9' => 0, 'QC12' => 999]);
        $qc9Worse = new DutchQualityVector(['QC9' => 1, 'QC12' => 0]);

        self::assertLessThan(0, $qc9Better->compare($qc9Worse));
        $difference = $qc9Better->firstDifference($qc9Worse);
        self::assertNotNull($difference);
        self::assertSame('QC9', $difference['criterion']);
    }

    public function testVectorComponentsRetainTheirDeclaredOrder(): void
    {
        $left = new DutchQualityVector(['QC7' => [0, 1], 'QC9' => 100]);
        $right = new DutchQualityVector(['QC7' => [1, 0], 'QC9' => 0]);

        self::assertLessThan(0, $left->compare($right));
        self::assertSame(['QC6', 'QC7', 'QN8', 'QC8', 'QC9'], array_slice(DutchQualityVector::ORDER, 0, 5));
        self::assertSame(array_map(static fn (int $value): int => -$value, $left->flatten()), $left->maximizingWeight());
    }
}
