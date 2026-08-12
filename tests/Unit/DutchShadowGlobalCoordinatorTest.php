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

    private function player(int $id, int $score): Player
    {
        return new Player($id, $id, 'P'.$id, 2200 - $id, '', '', '', true, Score::fromDecimal($score.'.0'));
    }
}
