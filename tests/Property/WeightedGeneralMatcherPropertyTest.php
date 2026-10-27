<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Property;

use OpenPairing\Engine\Matching\ExactWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use PHPUnit\Framework\TestCase;

final class WeightedGeneralMatcherPropertyTest extends TestCase
{
    public function testSeededGraphsPreserveSymmetryCardinalityCostAndOrderIndependence(): void
    {
        for ($seed = 1; $seed <= 20; ++$seed) {
            mt_srand($seed);
            $count = 2 + ($seed % 8);
            $vertices = range(1, $count);
            $edges = [];
            $edgeWeights = [];
            for ($left = 1; $left <= $count; ++$left) {
                for ($right = $left + 1; $right <= $count; ++$right) {
                    if (mt_rand(0, 99) < 55) {
                        $weight = [mt_rand(-5, 12), mt_rand(-3, 8)];
                        $edges[] = new WeightedEdge($left, $right, $weight);
                        $edgeWeights[$left.'-'.$right] = $weight;
                    }
                }
            }
            $matcher = new ExactWeightedGeneralMatcher();
            $forward = $matcher->match($vertices, $edges);
            $reverse = (new ExactWeightedGeneralMatcher())->match(array_reverse($vertices), array_reverse($edges));
            self::assertSame(WeightedMatchingStatus::Optimal, $forward->status, 'seed '.$seed);
            self::assertSame($forward->pairs, $reverse->pairs, 'order seed '.$seed);
            self::assertSame($forward->totalWeight, $reverse->totalWeight, 'weight seed '.$seed);
            $used = [];
            $sum = array_fill(0, count($forward->totalWeight), 0);
            foreach ($forward->pairs as $pair) {
                self::assertArrayNotHasKey($pair['left'], $used, 'left seed '.$seed);
                self::assertArrayNotHasKey($pair['right'], $used, 'right seed '.$seed);
                $used[$pair['left']] = true;
                $used[$pair['right']] = true;
                self::assertArrayHasKey($pair['left'].'-'.$pair['right'], $edgeWeights, 'edge seed '.$seed);
                foreach ($sum as $dimension => $component) {
                    $sum[$dimension] = $component + $edgeWeights[$pair['left'].'-'.$pair['right']][$dimension];
                }
            }
            self::assertSame($sum, $forward->totalWeight, 'sum seed '.$seed);
            self::assertSame(count($forward->pairs), $forward->cardinality, 'cardinality seed '.$seed);
            self::assertSame($count, (2 * $forward->cardinality) + count($forward->unmatched), 'coverage seed '.$seed);
        }
    }
}
