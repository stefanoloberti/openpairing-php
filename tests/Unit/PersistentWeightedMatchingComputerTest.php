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
    protected function tearDown(): void
    {
        putenv('OPENPAIRING_BIGINT_BACKEND');
    }

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

    public function testUnchangedReweightReusesOptimalResidualAfterMatchedFreeze(): void
    {
        $edges = [
            new WeightedEdge(1, 2, [5, 0]),
            new WeightedEdge(3, 4, [4, 0]),
            new WeightedEdge(1, 3, [1, 0]),
            new WeightedEdge(2, 4, [1, 0]),
        ];
        $computer = new PersistentWeightedMatchingComputer([1, 2, 3, 4], $edges);
        $initial = $computer->match();
        self::assertSame([['left' => 1, 'right' => 2], ['left' => 3, 'right' => 4]], $initial->pairs);

        $computer->freeze(1, 2);
        $computer->reweight([new WeightedEdge(3, 4, [4, 0])]);
        $residual = $computer->match();
        $fresh = (new BlossomWeightedGeneralMatcher())->match($computer->vertices(), $computer->edges(), true);
        $exact = (new ExactWeightedGeneralMatcher())->match($computer->vertices(), $computer->edges(), true);

        self::assertSame(0, $residual->statesExplored);
        self::assertSame($fresh->pairs, $residual->pairs);
        self::assertSame($fresh->totalWeight, $residual->totalWeight);
        self::assertSame($exact->pairs, $residual->pairs);
        self::assertSame($exact->totalWeight, $residual->totalWeight);
    }

    public function testChangedReweightInvalidatesOptimalResidual(): void
    {
        $computer = new PersistentWeightedMatchingComputer([1, 2, 3, 4, 5, 6], [
            new WeightedEdge(1, 2, [9]),
            new WeightedEdge(3, 4, [8]),
            new WeightedEdge(5, 6, [7]),
            new WeightedEdge(3, 5, [1]),
            new WeightedEdge(4, 6, [1]),
        ]);
        $initial = $computer->match();
        $computer->freeze(1, 2);
        $computer->reweight([
            new WeightedEdge(3, 4, [0]),
            new WeightedEdge(5, 6, [0]),
            new WeightedEdge(3, 5, [10]),
            new WeightedEdge(4, 6, [10]),
        ]);
        $changed = $computer->match();

        self::assertGreaterThan(0, $initial->statesExplored);
        self::assertGreaterThan(0, $changed->statesExplored);
        self::assertSame([['left' => 3, 'right' => 5], ['left' => 4, 'right' => 6]], $changed->pairs);
    }

    #[Group('stress')]
    public function testOneThousandUnchangedResidualSequencesRemainGloballyOptimal(): void
    {
        for ($seed = 1; $seed <= 1_000; ++$seed) {
            mt_srand($seed);
            $vertices = range(1, 4 + 2 * ($seed % 3));
            $computer = new PersistentWeightedMatchingComputer($vertices, $this->randomGraphEdges($vertices));

            while ($computer->vertices() !== []) {
                $persistent = $computer->match();
                $fresh = (new BlossomWeightedGeneralMatcher())->match($computer->vertices(), $computer->edges(), true);
                $exact = (new ExactWeightedGeneralMatcher())->match($computer->vertices(), $computer->edges(), true);
                self::assertSame($fresh->status, $persistent->status, 'fresh status seed '.$seed);
                self::assertSame($fresh->totalWeight, $persistent->totalWeight, 'fresh weight seed '.$seed);
                self::assertSame($exact->status, $persistent->status, 'exact status seed '.$seed);
                self::assertSame($exact->totalWeight, $persistent->totalWeight, 'exact weight seed '.$seed);
                if ($persistent->pairs === []) {
                    break;
                }

                $freeze = $persistent->pairs[0];
                $computer->freeze($freeze['left'], $freeze['right']);
                if ($computer->vertices() !== []) {
                    $computer->reweight($computer->edges());
                }
            }
        }
    }

    #[Group('stress')]
    public function testGmpAndLimbBackendsProduceIdenticalSequentialStates(): void
    {
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('ext-gmp is unavailable.');
        }

        for ($seed = 1; $seed <= 1_000; ++$seed) {
            self::assertSame(
                $this->backendTrace($seed, 'limbs'),
                $this->backendTrace($seed, 'gmp'),
                'backend trace seed '.$seed,
            );
        }
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

    /** @return list<array<string, mixed>> */
    private function backendTrace(int $seed, string $backend): array
    {
        putenv('OPENPAIRING_BIGINT_BACKEND='.$backend);
        mt_srand($seed);
        $vertices = range(1, 4 + 2 * ($seed % 3));
        $computer = new PersistentWeightedMatchingComputer($vertices, $this->randomGraphEdges($vertices));
        $trace = [];
        while ($computer->vertices() !== []) {
            $matching = $computer->match();
            $trace[] = [
                'vertices' => $computer->vertices(),
                'edges' => array_map(
                    static fn (WeightedEdge $edge): array => [$edge->left, $edge->right, $edge->weight],
                    $computer->edges(),
                ),
                'status' => $matching->status->value,
                'pairs' => $matching->pairs,
                'unmatched' => $matching->unmatched,
                'weight' => $matching->totalWeight,
                'operations' => $matching->statesExplored,
            ];
            if ($matching->pairs === []) {
                break;
            }
            $freeze = $matching->pairs[0];
            $computer->freeze($freeze['left'], $freeze['right']);
            if ($computer->vertices() !== []) {
                $computer->reweight($this->reweightedEdges($computer->edges()));
            }
        }

        return $trace;
    }
}
