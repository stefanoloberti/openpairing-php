<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Engine\Dutch\GlobalMatching\DutchC9Lifecycle;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchRoundGraph;
use OpenPairing\Engine\Matching\WeightedMatchingResult;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use PHPUnit\Framework\TestCase;

final class DutchC9LifecycleTest extends TestCase
{
    public function testAppliesWhenTheOnlyUnmatchedPathHasReachedThePabGroup(): void
    {
        $graph = $this->graph([1 => 2, 2 => 1, 3 => 1, 4 => 0]);
        $matching = $this->matching([['left' => 1, 'right' => 2]], [3, 4]);

        self::assertTrue((new DutchC9Lifecycle())->appliesInNextBracket(1, 1, [1, 2, 3, 4], $matching, $graph));
    }

    public function testDoesNotApplyWhenAPlayerInTheNextBracketIsMatchedBelowIt(): void
    {
        $graph = $this->graph([1 => 2, 2 => 1, 3 => 1, 4 => 0]);
        $matching = $this->matching([['left' => 1, 'right' => 4], ['left' => 2, 'right' => 3]], []);

        self::assertFalse((new DutchC9Lifecycle())->appliesInNextBracket(1, 1, [1, 2, 3, 4], $matching, $graph));
    }

    /** @param array<int, int> $levels */
    private function graph(array $levels): DutchRoundGraph
    {
        return new DutchRoundGraph(array_keys($levels), [], [], $levels);
    }

    /**
     * @param list<array{left: int, right: int}> $pairs
     * @param list<int> $unmatched
     */
    private function matching(array $pairs, array $unmatched): WeightedMatchingResult
    {
        return new WeightedMatchingResult(WeightedMatchingStatus::Optimal, $pairs, $unmatched, [], count($pairs), 0);
    }
}
