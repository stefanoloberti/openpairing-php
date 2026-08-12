<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Engine\Matching\BlossomWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\ExactWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class BlossomWeightedGeneralMatcherTest extends TestCase
{
    public function testDoesNotStopAtMaximumCardinalityBeforeMaximumWeight(): void
    {
        $edges = [
            new WeightedEdge(1, 5, [20]),
            new WeightedEdge(2, 6, [17]),
            new WeightedEdge(2, 7, [17]),
            new WeightedEdge(3, 4, [14]),
            new WeightedEdge(3, 6, [19]),
            new WeightedEdge(4, 6, [18]),
            new WeightedEdge(7, 8, [-7]),
        ];

        $result = (new BlossomWeightedGeneralMatcher())->match(range(1, 8), $edges, true);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertSame([44], $result->totalWeight);
        self::assertSame([
            ['left' => 1, 'right' => 5],
            ['left' => 2, 'right' => 6],
            ['left' => 3, 'right' => 4],
            ['left' => 7, 'right' => 8],
        ], $result->pairs);
    }

    public function testContractsOddCycleAndFindsWeightedPerfectMatching(): void
    {
        $edges = [
            new WeightedEdge(1, 2, [9, 0]),
            new WeightedEdge(2, 3, [9, 0]),
            new WeightedEdge(1, 3, [9, 0]),
            new WeightedEdge(1, 4, [8, 5]),
            new WeightedEdge(2, 5, [8, 4]),
            new WeightedEdge(3, 6, [8, 3]),
            new WeightedEdge(4, 5, [1, 0]),
            new WeightedEdge(5, 6, [1, 0]),
            new WeightedEdge(4, 6, [1, 0]),
        ];
        $result = (new BlossomWeightedGeneralMatcher())->match(range(1, 6), $edges, true);
        $oracle = (new ExactWeightedGeneralMatcher())->match(range(1, 6), $edges, true);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertSame($oracle->pairs, $result->pairs);
        self::assertSame($oracle->totalWeight, $result->totalWeight);
    }

    #[Group('stress')]
    public function testSeededSmallGraphsMatchIndependentExhaustiveOracleExactly(): void
    {
        for ($seed = 1; $seed <= 1_000; ++$seed) {
            mt_srand($seed);
            $count = 2 + ($seed % 9);
            $vertices = range(1, $count);
            $edges = [];
            for ($left = 1; $left <= $count; ++$left) {
                for ($right = $left + 1; $right <= $count; ++$right) {
                    if (mt_rand(0, 99) < 65) {
                        $edges[] = new WeightedEdge($left, $right, [mt_rand(-9, 12), mt_rand(-5, 8)]);
                    }
                }
            }
            $expected = (new ExactWeightedGeneralMatcher())->match($vertices, $edges);
            try {
                $actual = (new BlossomWeightedGeneralMatcher())->match(array_reverse($vertices), array_reverse($edges));
            } catch (\Throwable $error) {
                throw new \RuntimeException('seed '.$seed.' edges '.json_encode(array_map(
                    static fn (WeightedEdge $edge): array => [$edge->left, $edge->right, $edge->weight],
                    $edges,
                )).': '.$error->getMessage(), 0, $error);
            }

            self::assertSame($expected->status, $actual->status, 'status seed '.$seed);
            self::assertSame($expected->pairs, $actual->pairs, 'pairs seed '.$seed);
            self::assertSame($expected->unmatched, $actual->unmatched, 'unmatched seed '.$seed);
            self::assertSame($expected->totalWeight, $actual->totalWeight, 'weight seed '.$seed);
        }
    }

    public function testScalesBeyondExhaustiveMatcherLimit(): void
    {
        $vertices = range(1, 80);
        $edges = [];
        foreach ($vertices as $left) {
            for ($right = $left + 1; $right <= 80; ++$right) {
                $edges[] = new WeightedEdge($left, $right, [-abs($left - $right), -$right]);
            }
        }
        $result = (new BlossomWeightedGeneralMatcher())->match($vertices, $edges, true);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertSame(40, $result->cardinality);
        self::assertSame([], $result->unmatched);
    }

    #[Group('stress')]
    public function testCompleteGraphAtThreeHundredVerticesHasNoArchitecturalLimit(): void
    {
        $vertices = range(1, 300);
        $edges = [];
        foreach ($vertices as $left) {
            for ($right = $left + 1; $right <= 300; ++$right) {
                $edges[] = new WeightedEdge($left, $right, [-abs($left - $right)]);
            }
        }
        $result = (new BlossomWeightedGeneralMatcher())->match($vertices, $edges, true);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertSame(150, $result->cardinality);
        self::assertSame([], $result->unmatched);
    }
}
