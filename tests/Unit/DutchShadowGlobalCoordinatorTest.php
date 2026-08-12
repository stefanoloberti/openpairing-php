<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchShadowGlobalCoordinator;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchPreliminaryRoundMatcher;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchQualityRoundGraphBuilder;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchRoundGraphBuilder;
use OpenPairing\Engine\Matching\BlossomWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Engine\Matching\WeightedGeneralMatchingInterface;
use OpenPairing\Engine\Matching\WeightedMatchingResult;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use PHPUnit\Framework\TestCase;

final class DutchShadowGlobalCoordinatorTest extends TestCase
{
    public function testRecordsPreliminaryAndPerBracketMatchingTime(): void
    {
        $players = [
            $this->player(1, 2), $this->player(2, 2),
            $this->player(3, 1), $this->player(4, 1),
            $this->player(5, 0),
        ];
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 3, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, $players,
        );

        $result = (new DutchShadowGlobalCoordinator())->match($state);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertSame('preliminary', $result->timings[0]->phase);
        self::assertSame(
            ['preliminary', 'quality-HE', 'quality-HO-remainder', 'quality-HO-remainder', 'quality-HE', 'quality-HO-remainder', 'quality-HE'],
            array_map(static fn ($timing): string => $timing->phase, $result->timings),
        );
        foreach ($result->timings as $timing) {
            self::assertGreaterThanOrEqual(0.0, $timing->milliseconds);
        }
    }

    public function testFinalizesMdpOpponentBeforeBuildingRemainder(): void
    {
        $players = [
            $this->player(1, 2),
            $this->player(2, 1), $this->player(3, 1), $this->player(4, 1),
            $this->player(5, 0), $this->player(6, 0),
        ];
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 3, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, $players,
        );

        $result = (new DutchShadowGlobalCoordinator())->match($state);
        $phases = array_map(static fn ($timing): string => $timing->phase, $result->timings);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertContains('quality-HO-mdp', $phases);
        $mdpPosition = array_search('quality-HO-mdp', $phases, true);
        self::assertIsInt($mdpPosition);
        $remainderPosition = array_search(
            'quality-HO-remainder',
            array_slice($phases, $mdpPosition + 1, null, true),
            true,
        );
        self::assertIsInt($remainderPosition);
        self::assertLessThan($remainderPosition, $mdpPosition);
    }

    public function testReweightingPreservesTheInducedActiveGraphTopology(): void
    {
        $players = [
            $this->player(1, 2), $this->player(2, 2),
            $this->player(3, 1), $this->player(4, 1),
            $this->player(5, 0), $this->player(6, 0),
        ];
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 3, 6, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, $players,
        );
        $recorder = new class implements WeightedGeneralMatchingInterface {
            /** @var list<array{vertices: list<int>, edges: list<string>}> */
            public array $calls = [];

            public function match(array $vertices, array $edges, bool $requirePerfect = false): WeightedMatchingResult
            {
                $keys = array_map(static fn (WeightedEdge $edge): string => min($edge->left, $edge->right).':'.max($edge->left, $edge->right), $edges);
                sort($keys, SORT_STRING);
                sort($vertices, SORT_NUMERIC);
                $this->calls[] = ['vertices' => $vertices, 'edges' => $keys];

                return (new BlossomWeightedGeneralMatcher())->match($vertices, $edges, $requirePerfect);
            }
        };
        $coordinator = new DutchShadowGlobalCoordinator(
            new DutchRoundGraphBuilder(),
            new DutchPreliminaryRoundMatcher(),
            new DutchQualityRoundGraphBuilder(),
            $recorder,
        );

        $result = $coordinator->match($state);

        self::assertSame(WeightedMatchingStatus::Optimal, $result->status);
        self::assertNotEmpty($recorder->calls);
        $edgesByActiveVertices = [];
        foreach ($recorder->calls as $call) {
            $key = implode(',', $call['vertices']);
            if (isset($edgesByActiveVertices[$key])) {
                self::assertSame($edgesByActiveVertices[$key], $call['edges']);
            } else {
                $edgesByActiveVertices[$key] = $call['edges'];
            }
        }
    }

    public function testPabSelectionAddsRepeatedDownfloatComplementAtCanonicalQcPositions(): void
    {
        $repeat = new Player(1, 1, 'Repeat', 2200, '', '', '', true, Score::fromDecimal('2.5'), floatHistory: ['none', 'down']);
        $fresh = new Player(2, 2, 'Fresh', 2190, '', '', '', true, Score::fromDecimal('2.5'), floatHistory: ['none', 'none']);
        $other = new Player(3, 3, 'Other', 2180, '', '', '', true, Score::fromDecimal('3.0'));
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 5, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, [$repeat, $fresh, $other],
        );
        $graph = (new DutchRoundGraphBuilder())->build($state);
        $edges = [new WeightedEdge(1, 3, array_fill(0, 24, 0))];
        $method = new \ReflectionMethod(DutchShadowGlobalCoordinator::class, 'pabSelectionWeightedEdges');
        /** @var list<WeightedEdge> $weighted */
        $weighted = $method->invoke(new DutchShadowGlobalCoordinator(), $edges, $state, $graph, 0, false, 1);
        // Two PAB prefix fields precede the base vector. With max PSD 1,
        // QC14 and QC16 are base positions 10 and 12 respectively.
        self::assertSame(1, $weighted[0]->weight[12]);
        self::assertSame(0, $weighted[0]->weight[14]);
    }

    public function testMatchedMdpConstraintUsesOnlyUnsignedIncidentBits(): void
    {
        $players = [$this->player(1, 2), $this->player(2, 1), $this->player(3, 1)];
        $state = new TournamentState(
            TournamentState::SCHEMA_VERSION, 3, 5, 'fide-dutch-2026', Colour::White,
            Score::fromDecimal('1.0'), Score::fromDecimal('0.5'), 256, $players,
        );
        $graph = (new DutchRoundGraphBuilder())->build($state);
        $edges = [
            new WeightedEdge(1, 2, [0, 0, 0]),
            new WeightedEdge(1, 3, [0, 0, 0]),
            new WeightedEdge(2, 3, [0, 0, 0]),
        ];
        $method = new \ReflectionMethod(DutchShadowGlobalCoordinator::class, 'constraintWeightedEdges');
        /** @var list<WeightedEdge> $weighted */
        $weighted = $method->invoke(
            new DutchShadowGlobalCoordinator(),
            $edges,
            $graph,
            0,
            [1 => true],
            2,
            null,
            [1 => 1, 2 => 2, 3 => 3],
        );

        self::assertSame([1, 1, 0], array_map(static fn (WeightedEdge $edge): int => $edge->weight[2], $weighted));
        foreach ($weighted as $edge) {
            self::assertTrue(in_array($edge->weight[2], [0, 1], true));
        }
    }

    private function player(int $id, int $score): Player
    {
        return new Player($id, $id, 'P'.$id, 2200 - $id, '', '', '', true, Score::fromDecimal($score.'.0'));
    }
}
