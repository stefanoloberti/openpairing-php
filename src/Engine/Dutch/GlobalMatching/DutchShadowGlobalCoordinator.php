<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Matching\BlossomWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\PersistentWeightedMatchingComputer;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Engine\Matching\WeightedGeneralMatchingInterface;
use OpenPairing\Engine\Matching\WeightedMatchingResult;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;

/** Development-only progressive persistent-bracket shadow coordinator. */
final readonly class DutchShadowGlobalCoordinator
{
    public function __construct(
        private DutchRoundGraphBuilder $graphBuilder = new DutchRoundGraphBuilder(),
        private DutchPreliminaryRoundMatcher $preliminary = new DutchPreliminaryRoundMatcher(),
        private DutchQualityRoundGraphBuilder $qualityGraphBuilder = new DutchQualityRoundGraphBuilder(),
        private WeightedGeneralMatchingInterface $matcher = new BlossomWeightedGeneralMatcher(),
    ) {
    }

    public function match(TournamentState $state): DutchGlobalRoundResult
    {
        $graph = $this->graphBuilder->build($state);
        $preliminaryStarted = hrtime(true);
        $preliminary = $this->preliminary->match($state);
        $timings = [new DutchBracketTiming(
            max($graph->scoreRankByPlayerId ?: [0]),
            'preliminary',
            (hrtime(true) - $preliminaryStarted) / 1_000_000,
            $preliminary->statesExplored,
            count($graph->vertices),
        )];
        $freezeEvents = [];
        if ($preliminary->status !== WeightedMatchingStatus::Optimal) {
            return new DutchGlobalRoundResult($preliminary->status, [], $graph->vertices, null, $timings, $freezeEvents);
        }

        $preliminaryBye = $preliminary->unmatchedPlayerIds[0] ?? null;
        $pabScoreLevel = $preliminaryBye === null ? null : $graph->scoreRankByPlayerId[$preliminaryBye];
        $c9Applies = $preliminaryBye !== null;
        $remaining = $graph->vertices;
        if ($preliminaryBye !== null) {
            $remaining[] = 0; // virtual PAB vertex
        }
        $scoreLevels = array_values(array_unique($graph->scoreRankByPlayerId));
        rsort($scoreLevels, SORT_NUMERIC);
        $frozenPairs = [];
        $selectedBye = null;
        $computer = null;

        foreach ($scoreLevels as $bracketIndex => $scoreLevel) {
            $realRemaining = array_values(array_filter($remaining, static fn (int $id): bool => $id !== 0));
            if ($realRemaining === []) {
                break;
            }
            if (count($remaining) === 2 && count($realRemaining) === 1 && in_array(0, $remaining, true)) {
                $playerId = $realRemaining[0];
                if ($pabScoreLevel !== $scoreLevel || $graph->scoreRankByPlayerId[$playerId] !== $scoreLevel) {
                    return new DutchGlobalRoundResult(WeightedMatchingStatus::NoMatching, $frozenPairs, $remaining, null, $timings, $freezeEvents);
                }
                $selectedBye = $playerId;
                $freezeEvents[] = new DutchFreezeEvent(
                    count($freezeEvents), $bracketIndex, $scoreLevel, 0, $playerId, count($remaining), 0,
                );
                $remaining = [];
                break;
            }
            $current = array_values(array_filter(
                $realRemaining,
                static fn (int $id): bool => $graph->scoreRankByPlayerId[$id] >= $scoreLevel,
            ));
            if ($current === []) {
                continue;
            }
            $bsn = [];
            foreach ($current as $index => $id) {
                $bsn[$id] = $index + 1;
            }

            $he = $this->rematch($state, $graph, $remaining, $realRemaining, $scoreLevel, $pabScoreLevel, $c9Applies, $bsn, 0, 'HE', $timings, $computer);
            if ($he->status !== WeightedMatchingStatus::Optimal) {
                return new DutchGlobalRoundResult(WeightedMatchingStatus::NoMatching, [], $realRemaining, null, $timings, $freezeEvents);
            }
            $selectedMdpIds = $this->selectedMdpIds($he, $graph, $scoreLevel);
            $selectedMdpPairs = $this->selectedMdpPairs($he, $graph, $scoreLevel);
            $homogeneousPairs = 0;
            foreach ($he->pairs as $pair) {
                if ($pair['left'] !== 0 && $pair['right'] !== 0
                    && $graph->scoreRankByPlayerId[$pair['left']] === $scoreLevel
                    && $graph->scoreRankByPlayerId[$pair['right']] === $scoreLevel) {
                    ++$homogeneousPairs;
                }
            }

            $ho = $this->rematch(
                $state,
                $graph,
                $remaining,
                $realRemaining,
                $scoreLevel,
                $pabScoreLevel,
                $c9Applies,
                $bsn,
                $homogeneousPairs,
                'HO',
                $timings,
                $computer,
                $selectedMdpIds,
                $selectedMdpPairs,
            );
            if ($ho->status !== WeightedMatchingStatus::Optimal) {
                return new DutchGlobalRoundResult(WeightedMatchingStatus::NoMatching, [], $realRemaining, null, $timings, $freezeEvents);
            }
            $freeze = [];
            $events = [];
            foreach ($ho->pairs as $pair) {
                if ($pair['left'] === 0 || $pair['right'] === 0) {
                    $playerId = $pair['left'] === 0 ? $pair['right'] : $pair['left'];
                    if ($graph->scoreRankByPlayerId[$playerId] === $scoreLevel) {
                        $selectedBye = $playerId;
                        $freeze[] = 0;
                        $freeze[] = $playerId;
                        $events[] = [0, $playerId];
                    }
                    continue;
                }
                $leftLevel = $graph->scoreRankByPlayerId[$pair['left']];
                $rightLevel = $graph->scoreRankByPlayerId[$pair['right']];
                if (min($leftLevel, $rightLevel) === $scoreLevel) {
                    $frozenPairs[] = $pair;
                    $freeze[] = $pair['left'];
                    $freeze[] = $pair['right'];
                    $events[] = [$pair['left'], $pair['right']];
                }
            }
            $frozen = array_fill_keys($freeze, true);
            $remainingBefore = count($remaining);
            $remaining = array_values(array_filter($remaining, static fn (int $id): bool => !isset($frozen[$id])));
            foreach ($events as [$left, $right]) {
                $freezeEvents[] = new DutchFreezeEvent(
                    count($freezeEvents), $bracketIndex, $scoreLevel,
                    min($left, $right), max($left, $right),
                    $remainingBefore, count($remaining),
                );
                $computer->freeze($left, $right);
            }
        }

        if ($remaining !== []) {
            return new DutchGlobalRoundResult(WeightedMatchingStatus::NoMatching, $frozenPairs, $remaining, $selectedBye, $timings, $freezeEvents);
        }

        usort($frozenPairs, static fn (array $left, array $right): int =>
            ($left['left'] <=> $right['left']) ?: ($left['right'] <=> $right['right'])
        );

        return new DutchGlobalRoundResult(WeightedMatchingStatus::Optimal, $frozenPairs, [], $selectedBye, $timings, $freezeEvents);
    }

    /**
     * @param list<int> $remaining
     * @param list<int> $realRemaining
     * @param array<int, int> $bsn
     * @param list<DutchBracketTiming> $timings
     * @param-out PersistentWeightedMatchingComputer $computer
     * @param list<int> $selectedMdpIds
     * @param list<array{left: int, right: int}> $selectedMdpPairs
     */
    private function rematch(
        TournamentState $state,
        DutchRoundGraph $graph,
        array $remaining,
        array $realRemaining,
        int $scoreLevel,
        ?int $pabScoreLevel,
        bool $c9Applies,
        array $bsn,
        int $homogeneousPairs,
        string $phase,
        array &$timings,
        ?PersistentWeightedMatchingComputer &$computer,
        array $selectedMdpIds = [],
        array $selectedMdpPairs = [],
    ): WeightedMatchingResult {
        $started = hrtime(true);
        $edges = $this->qualityGraphBuilder->edges(
            $state,
            $scoreLevel,
            $pabScoreLevel,
            $realRemaining,
            $bsn,
            $homogeneousPairs,
        );
        if ($pabScoreLevel !== null && $edges !== [] && in_array(0, $remaining, true)) {
            $edges = [...$edges, ...$this->qualityGraphBuilder->pabEdges(
                $state,
                $scoreLevel,
                $pabScoreLevel,
                $c9Applies,
                $edges[0]->weight,
            )];
        }
        $edges = $this->constraintWeightedEdges($edges, $graph, $scoreLevel, $selectedMdpIds, $selectedMdpPairs);
        if ($computer === null) {
            $computer = new PersistentWeightedMatchingComputer($remaining, $edges, $this->matcher);
        } else {
            $computer->reweight($edges);
        }
        $matching = $computer->match(true);
        $timings[] = new DutchBracketTiming(
            $scoreLevel,
            'quality-'.$phase,
            (hrtime(true) - $started) / 1_000_000,
            $matching->statesExplored,
            count($remaining),
        );

        return $matching;
    }

    /**
     * Keep every active edge. Former filters become the highest-priority
     * component, so a zero-violation perfect matching dominates any fallback.
     *
     * @param list<WeightedEdge> $edges
     * @param list<int> $selectedMdpIds
     * @param list<array{left: int, right: int}> $selectedMdpPairs
     * @return list<WeightedEdge>
     */
    private function constraintWeightedEdges(array $edges, DutchRoundGraph $graph, int $scoreLevel, array $selectedMdpIds, array $selectedMdpPairs): array
    {
        $selectedMdpSet = array_fill_keys($selectedMdpIds, true);
        $selectedPairSet = [];
        foreach ($selectedMdpPairs as $pair) {
            $selectedPairSet[min($pair['left'], $pair['right']).':'.max($pair['left'], $pair['right'])] = true;
        }

        return array_map(static function (WeightedEdge $edge) use ($graph, $scoreLevel, $selectedMdpSet, $selectedPairSet): WeightedEdge {
            $violation = 0;
            if ($edge->left !== 0 && $edge->right !== 0) {
                if ($graph->scoreRankByPlayerId[$edge->left] > $scoreLevel
                    && $graph->scoreRankByPlayerId[$edge->right] > $scoreLevel) {
                    ++$violation;
                }
                $leftSelected = isset($selectedMdpSet[$edge->left]);
                $rightSelected = isset($selectedMdpSet[$edge->right]);
                if (($leftSelected || $rightSelected)
                    && !isset($selectedPairSet[min($edge->left, $edge->right).':'.max($edge->left, $edge->right)])) {
                    ++$violation;
                }
            }

            return new WeightedEdge($edge->left, $edge->right, [-$violation, ...$edge->weight]);
        }, $edges);
    }

    /** @return list<int> */
    private function selectedMdpIds(WeightedMatchingResult $matching, DutchRoundGraph $graph, int $scoreLevel): array
    {
        $mdpIds = [];
        foreach ($matching->pairs as $pair) {
            if ($pair['left'] === 0 || $pair['right'] === 0) {
                continue;
            }
            $leftMdp = $graph->scoreRankByPlayerId[$pair['left']] > $scoreLevel;
            $rightMdp = $graph->scoreRankByPlayerId[$pair['right']] > $scoreLevel;
            if ($leftMdp === $rightMdp) {
                continue;
            }
            $mdpIds[] = $leftMdp ? $pair['left'] : $pair['right'];
        }
        usort($mdpIds, static fn (int $left, int $right): int =>
            ($graph->scoreRankByPlayerId[$right] <=> $graph->scoreRankByPlayerId[$left])
            ?: ($graph->playersById[$left]->pairingNumber <=> $graph->playersById[$right]->pairingNumber)
        );

        return $mdpIds;
    }

    /** @return list<array{left: int, right: int}> */
    private function selectedMdpPairs(WeightedMatchingResult $matching, DutchRoundGraph $graph, int $scoreLevel): array
    {
        $pairs = [];
        foreach ($matching->pairs as $pair) {
            if ($pair['left'] === 0 || $pair['right'] === 0) {
                continue;
            }
            if (($graph->scoreRankByPlayerId[$pair['left']] > $scoreLevel)
                !== ($graph->scoreRankByPlayerId[$pair['right']] > $scoreLevel)) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

}
