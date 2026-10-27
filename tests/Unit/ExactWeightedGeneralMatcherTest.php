<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Engine\Matching\ExactWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\MatchingLimitExceeded;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExactWeightedGeneralMatcherTest extends TestCase
{
    public function testFindsWeightedPerfectMatchingInGeneralGraph(): void
    {
        $result = (new ExactWeightedGeneralMatcher())->match([1, 2, 3, 4], [
            new WeightedEdge(1, 2, [1, 0]),
            new WeightedEdge(3, 4, [1, 0]),
            new WeightedEdge(1, 3, [2, 0]),
            new WeightedEdge(2, 4, [0, 9]),
            new WeightedEdge(1, 4, [0, 0]),
            new WeightedEdge(2, 3, [0, 0]),
        ], true);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertSame([['left' => 1, 'right' => 3], ['left' => 2, 'right' => 4]], $result->pairs);
        self::assertSame([2, 9], $result->totalWeight);
    }

    public function testHandlesOddCycleWithoutBipartiteAssumptions(): void
    {
        $edges = [];
        foreach ([[1, 2], [2, 3], [3, 4], [4, 5], [5, 1]] as [$left, $right]) {
            $edges[] = new WeightedEdge($left, $right, [1]);
        }
        $result = (new ExactWeightedGeneralMatcher())->match([5, 3, 1, 4, 2], array_reverse($edges));

        self::assertSame(2, $result->cardinality);
        self::assertCount(1, $result->unmatched);
        self::assertSame([['left' => 1, 'right' => 2], ['left' => 3, 'right' => 4]], $result->pairs);
    }

    public function testReportsNoPerfectMatching(): void
    {
        $result = (new ExactWeightedGeneralMatcher())->match(
            [1, 2, 3, 4],
            [new WeightedEdge(1, 2, [1])],
            true,
        );

        self::assertSame(WeightedMatchingStatus::NoMatching, $result->status);
        self::assertSame([], $result->pairs);
    }

    /** @return iterable<string, array{list<int>, list<WeightedEdge>}> */
    public static function inputOrders(): iterable
    {
        $edges = [
            new WeightedEdge(1, 2, [1]),
            new WeightedEdge(3, 4, [1]),
            new WeightedEdge(1, 3, [1]),
            new WeightedEdge(2, 4, [1]),
        ];
        yield 'forward' => [[1, 2, 3, 4], $edges];
        yield 'reversed' => [[4, 3, 2, 1], array_reverse($edges)];
    }

    /**
     * @param list<int> $vertices
     * @param list<WeightedEdge> $edges
     */
    #[DataProvider('inputOrders')]
    public function testTieBreakIsIndependentOfInputOrder(array $vertices, array $edges): void
    {
        $result = (new ExactWeightedGeneralMatcher())->match($vertices, $edges, true);

        self::assertSame([['left' => 1, 'right' => 2], ['left' => 3, 'right' => 4]], $result->pairs);
    }

    public function testEnforcesExplicitVertexLimit(): void
    {
        $this->expectException(MatchingLimitExceeded::class);
        (new ExactWeightedGeneralMatcher())->match(range(1, ExactWeightedGeneralMatcher::MAX_VERTICES + 1), []);
    }
}
