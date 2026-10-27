<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Engine\Dutch\GlobalMatching\DutchFreezeLookahead;
use OpenPairing\Engine\Matching\WeightedEdge;
use PHPUnit\Framework\TestCase;

final class DutchFreezeLookaheadTest extends TestCase
{
    public function testDoesNotFreezeLocallyEquivalentEdgeWithInferiorNextBracket(): void
    {
        $localFirst = new WeightedEdge(1, 2, [10, 0]);
        $globalFirst = new WeightedEdge(1, 3, [10, 0]);
        $edges = [
            $localFirst,
            $globalFirst,
            new WeightedEdge(3, 4, [0, 0]),
            new WeightedEdge(2, 4, [0, 5]),
            new WeightedEdge(5, 6, [0, 0]),
        ];

        $decision = (new DutchFreezeLookahead())->choose(
            [1, 2, 3, 4, 5, 6],
            $edges,
            [$localFirst, $globalFirst],
        );

        self::assertNotNull($decision);
        self::assertSame([1, 3], [$decision->edge->left, $decision->edge->right]);
        self::assertSame([10, 5], $decision->wholeRoundWeight);
    }

    public function testRejectsCandidateWhoseRemovalMakesTheSuffixIncomplete(): void
    {
        $deadEnd = new WeightedEdge(1, 2, [10]);
        $completable = new WeightedEdge(1, 3, [9]);
        $decision = (new DutchFreezeLookahead())->choose(
            [1, 2, 3, 4],
            [$deadEnd, $completable, new WeightedEdge(2, 4, [0])],
            [$deadEnd, $completable],
        );

        self::assertNotNull($decision);
        self::assertSame([1, 3], [$decision->edge->left, $decision->edge->right]);
    }
}
