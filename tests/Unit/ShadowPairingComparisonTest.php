<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Diagnostics\ShadowPairingCategory;
use OpenPairing\Diagnostics\ShadowPairingComparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShadowPairingComparisonTest extends TestCase
{
    /**
     * @param array{pairs: array<string, string>, bye: int|null} $legacy
     * @param array{pairs: array<string, string>, bye: int|null} $candidate
     */
    #[DataProvider('comparisons')]
    public function testClassifiesEveryShadowOutcome(
        array $legacy,
        array $candidate,
        ShadowPairingCategory $expected,
    ): void {
        $reference = self::signature('1>2', 3);

        self::assertSame($expected, ShadowPairingComparison::classify($reference, $legacy, $candidate));
    }

    /** @return iterable<string, array{array{pairs: array<string, string>, bye: int|null}, array{pairs: array<string, string>, bye: int|null}, ShadowPairingCategory}> */
    public static function comparisons(): iterable
    {
        $exact = self::signature('1>2', 3);
        $firstDeviation = self::signature('1>3', 2);
        $secondDeviation = self::signature('2>3', 1);

        yield 'both exact' => [$exact, $exact, ShadowPairingCategory::BothExact];
        yield 'candidate fixes legacy' => [$firstDeviation, $exact, ShadowPairingCategory::CandidateProgress];
        yield 'candidate breaks legacy exact' => [$exact, $firstDeviation, ShadowPairingCategory::CandidateRegression];
        yield 'same known deviation' => [$firstDeviation, $firstDeviation, ShadowPairingCategory::SameDeviation];
        yield 'two deviations need oracle' => [$firstDeviation, $secondDeviation, ShadowPairingCategory::DivergentDeviation];
    }

    /** @return array{pairs: array<string, string>, bye: int|null} */
    private static function signature(string $colouredPair, int $bye): array
    {
        $players = array_map('intval', explode('>', $colouredPair));
        sort($players);

        return ['pairs' => [$players[0].'-'.$players[1] => $colouredPair], 'bye' => $bye];
    }
}
