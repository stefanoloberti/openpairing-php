<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Engine\Matching\BlossomWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\ExactWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\PersistentWeightedMatchingComputer;
use OpenPairing\Engine\Matching\WeightedEdge;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PersistentWeightedMatchingComputerTest extends TestCase
{
    #[Group('stress')]
    public function testOneThousandFreezeAndReweightSequencesMatchFreshAndExhaustiveSolvers(): void
    {
        for ($seed = 1; $seed <= 1_000; ++$seed) {
            mt_srand($seed);
            $vertices = range(1, 4 + 2 * ($seed % 3));
            $edges = $this->randomGraphEdges($vertices);
            $computer = new PersistentWeightedMatchingComputer($vertices, $edges);

            while ($computer->vertices() !== []) {
                $persistent = $computer->match();
                $fresh = (new BlossomWeightedGeneralMatcher())->match($computer->vertices(), $computer->edges(), true);
                $exhaustive = (new ExactWeightedGeneralMatcher())->match($computer->vertices(), $computer->edges(), true);

                self::assertSame($fresh->status, $persistent->status, 'fresh status seed '.$seed);
                self::assertSame($fresh->pairs, $persistent->pairs, 'fresh pairs seed '.$seed);
                self::assertSame($fresh->totalWeight, $persistent->totalWeight, 'fresh weight seed '.$seed);
                self::assertSame($exhaustive->status, $persistent->status, 'exhaustive status seed '.$seed);
                self::assertSame($exhaustive->totalWeight, $persistent->totalWeight, 'exhaustive weight seed '.$seed.' vertices '.json_encode($computer->vertices()).' edges '.json_encode(array_map(
                    static fn (WeightedEdge $edge): array => [$edge->left, $edge->right, $edge->weight],
                    $computer->edges(),
                )));

                $freeze = $persistent->pairs[0];
                $computer->freeze($freeze['left'], $freeze['right']);
                if ($computer->vertices() !== []) {
                    $computer->reweight($this->reweightedEdges($computer->edges()));
                }
            }
        }
    }

    public function testReweightCannotChangeTopology(): void
    {
        $computer = new PersistentWeightedMatchingComputer([1, 2, 3, 4], [
            new WeightedEdge(1, 2, [1]),
            new WeightedEdge(3, 4, [1]),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $computer->reweight([new WeightedEdge(1, 2, [2])]);
    }

    /**
     * @param list<int> $vertices
     * @return list<WeightedEdge>
     */
    private function randomGraphEdges(array $vertices): array
    {
        $edges = [];
        foreach ($vertices as $left) {
            foreach ($vertices as $right) {
                if ($left >= $right || (($right !== $left + 1 || ($left & 1) === 0) && mt_rand(0, 99) >= 60)) {
                    continue;
                }
                $edges[] = new WeightedEdge($left, $right, [
                    mt_rand(-20, 20),
                    mt_rand(-10, 10),
                    -($left * 100 + $right),
                ]);
            }
        }

        return $edges;
    }

    /**
     * @param list<WeightedEdge> $edges
     * @return list<WeightedEdge>
     */
    private function reweightedEdges(array $edges): array
    {
        return array_map(static fn (WeightedEdge $edge): WeightedEdge => new WeightedEdge(
            $edge->left,
            $edge->right,
            [mt_rand(-20, 20), mt_rand(-10, 10), -($edge->left * 100 + $edge->right)],
        ), $edges);
    }
}
