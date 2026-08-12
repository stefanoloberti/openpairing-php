<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Engine\Matching\DynamicUInt;
use OpenPairing\Engine\Matching\LexicographicWeightEncoder;
use OpenPairing\Engine\Matching\WeightedEdge;
use PHPUnit\Framework\TestCase;

final class LexicographicWeightEncoderTest extends TestCase
{
    public function testCardinalityDominatesEveryWeightComponent(): void
    {
        $problem = (new LexicographicWeightEncoder())->encode([1, 2, 3, 4], [
            new WeightedEdge(1, 2, [-1_000_000, 5]),
            new WeightedEdge(1, 3, [1_000_000, -5]),
            new WeightedEdge(2, 4, [-1_000_000, 5]),
        ]);
        $weights = $this->weights($problem->edges);

        self::assertGreaterThan(
            0,
            $weights['1-2']->add($weights['2-4'])->compare($weights['1-3']),
        );
    }

    public function testFirstComponentDominatesAllFollowingComponents(): void
    {
        $problem = (new LexicographicWeightEncoder())->encode([1, 2, 3, 4], [
            new WeightedEdge(1, 2, [1, -1_000_000]),
            new WeightedEdge(3, 4, [1, -1_000_000]),
            new WeightedEdge(1, 3, [0, 1_000_000]),
            new WeightedEdge(2, 4, [0, 1_000_000]),
        ]);
        $weights = $this->weights($problem->edges);

        self::assertGreaterThan(
            0,
            $weights['1-2']->add($weights['3-4'])->compare($weights['1-3']->add($weights['2-4'])),
        );
    }

    public function testPairingNumberTieBreakIsIndependentOfInputOrder(): void
    {
        $edges = [
            new WeightedEdge(1, 2, [0]),
            new WeightedEdge(3, 4, [0]),
            new WeightedEdge(1, 3, [0]),
            new WeightedEdge(2, 4, [0]),
        ];
        $problem = (new LexicographicWeightEncoder())->encode([4, 3, 2, 1], array_reverse($edges));
        $weights = $this->weights($problem->edges);

        self::assertGreaterThan(
            0,
            $weights['1-2']->add($weights['3-4'])->compare($weights['1-3']->add($weights['2-4'])),
        );
        self::assertGreaterThanOrEqual(128, $problem->bitWidth);
    }

    /**
     * @param list<\OpenPairing\Engine\Matching\EncodedWeightedEdge> $edges
     * @return array<string, DynamicUInt>
     */
    private function weights(array $edges): array
    {
        $result = [];
        foreach ($edges as $edge) {
            $result[$edge->left.'-'.$edge->right] = $edge->weight;
        }

        return $result;
    }
}
