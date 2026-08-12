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
        $c9Applies = $this->singleDownfloaterIsByeAssignee($preliminary, $graph);
        $remaining = $graph->vertices;
        $scoreLevels = array_values(array_unique($graph->scoreRankByPlayerId));
        rsort($scoreLevels, SORT_NUMERIC);
        $frozenPairs = [];
        $selectedBye = null;
        $computer = null;

        foreach ($scoreLevels as $bracketIndex => $scoreLevel) {
            $realRemaining = $remaining;
            if ($realRemaining === []) {
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
            $homogeneousPairs = 0;
            foreach ($he->pairs as $pair) {
                if ($pair['left'] !== 0 && $pair['right'] !== 0
                    && $graph->scoreRankByPlayerId[$pair['left']] === $scoreLevel
                    && $graph->scoreRankByPlayerId[$pair['right']] === $scoreLevel) {
                    ++$homogeneousPairs;
                }
            }

            // BBP first finalizes the opponents of the HE-selected MDPs one at
            // a time. The rest of the bracket remains available to prove the
            // quality and completability of the suffix after every freeze.
            while ($selectedMdpIds !== []) {
                $mdpId = $selectedMdpIds[0];
                $ho = $this->rematch(
                    $state, $graph, $remaining, $remaining, $scoreLevel,
                    $pabScoreLevel, $c9Applies, $bsn, $homogeneousPairs,
                    'HO-mdp', $timings, $computer, $selectedMdpIds,
                );
                $pair = $this->pairForPlayer($ho, $mdpId);
                if ($ho->status !== WeightedMatchingStatus::Optimal || $pair === null) {
                    return new DutchGlobalRoundResult(WeightedMatchingStatus::NoMatching, [], $remaining, null, $timings, $freezeEvents);
                }
                $opponentId = $pair['left'] === $mdpId ? $pair['right'] : $pair['left'];
                if ($graph->scoreRankByPlayerId[$opponentId] !== $scoreLevel) {
                    return new DutchGlobalRoundResult(WeightedMatchingStatus::NoMatching, [], [
                        $mdpId,
                        $opponentId,
                        $scoreLevel,
                        $graph->scoreRankByPlayerId[$mdpId],
                        $graph->scoreRankByPlayerId[$opponentId],
                        ...$selectedMdpIds,
                    ], null, $timings, $freezeEvents);
                }
                $this->freezePair(
                    $pair, $bracketIndex, $scoreLevel, $computer,
                    $remaining, $frozenPairs, $freezeEvents,
                );
                array_shift($selectedMdpIds);
            }

            // Only after heterogeneous opponents are final does the current
            // matching define the remainder. Freeze resident-resident pairs;
            // residents matched below remain active and move to the next bracket.
            while (count($remaining) > ($preliminaryBye === null ? 0 : 1)) {
                $remainder = $this->rematch(
                    $state, $graph, $remaining, $remaining, $scoreLevel,
                    $pabScoreLevel, $c9Applies, $bsn, $homogeneousPairs,
                    'HO-remainder', $timings, $computer, [],
                );
                if ($remainder->status !== WeightedMatchingStatus::Optimal) {
                    return new DutchGlobalRoundResult(WeightedMatchingStatus::NoMatching, [], $remaining, null, $timings, $freezeEvents);
                }
                $residentPairs = array_values(array_filter(
                    $remainder->pairs,
                    static fn (array $pair): bool => $graph->scoreRankByPlayerId[$pair['left']] === $scoreLevel
                        && $graph->scoreRankByPlayerId[$pair['right']] === $scoreLevel,
                ));
                if ($residentPairs === []) {
                    break;
                }
                usort($residentPairs, static function (array $left, array $right) use ($bsn): int {
                    $leftFirst = min($bsn[$left['left']], $bsn[$left['right']]);
                    $rightFirst = min($bsn[$right['left']], $bsn[$right['right']]);

                    return ($leftFirst <=> $rightFirst)
                        ?: (max($bsn[$left['left']], $bsn[$left['right']]) <=> max($bsn[$right['left']], $bsn[$right['right']]));
                });
                $this->freezePair(
                    $residentPairs[0], $bracketIndex, $scoreLevel, $computer,
                    $remaining, $frozenPairs, $freezeEvents,
                );
            }
        }

        if (count($remaining) === 1 && $preliminaryBye !== null) {
            $selectedBye = $remaining[0];
            if ($graph->scoreRankByPlayerId[$selectedBye] !== $pabScoreLevel
                || !(new \OpenPairing\Engine\Dutch\DutchRules())->isEligibleForPairingAllocatedBye($state, $graph->playersById[$selectedBye])) {
                return new DutchGlobalRoundResult(WeightedMatchingStatus::NoMatching, $frozenPairs, $remaining, null, $timings, $freezeEvents);
            }
            $freezeEvents[] = new DutchFreezeEvent(
                count($freezeEvents), count($scoreLevels), $pabScoreLevel, 0, $selectedBye, 1, 0,
            );
            $remaining = [];
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
     * @param list<int>|null $selectedMdpIds Null disables the matched-MDP
     * constraint during HE; an empty list means that no remaining MDP may be
     * paired in the current bracket.
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
        ?array $selectedMdpIds = null,
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
        if ($pabScoreLevel !== null) {
            $edges = $this->pabSelectionWeightedEdges($edges, $state, $graph, $pabScoreLevel, $c9Applies);
        }
        if ($selectedMdpIds !== null) {
            $edges = $this->constraintWeightedEdges($edges, $graph, $scoreLevel, $selectedMdpIds);
        }
        $widths = array_values(array_unique(array_map(static fn (WeightedEdge $edge): int => count($edge->weight), $edges)));
        if (count($widths) > 1) {
            $shapes = array_map(
                static fn (WeightedEdge $edge): string => sprintf('%d-%d:%d', $edge->left, $edge->right, count($edge->weight)),
                $edges,
            );
            throw new \LogicException(sprintf(
                'Dutch %s score level %d produced non-uniform edge widths: %s (%s).',
                $phase,
                $scoreLevel,
                implode(',', $widths),
                implode(' ', $shapes),
            ));
        }
        if ($computer === null) {
            $computer = new PersistentWeightedMatchingComputer($remaining, $edges, $this->matcher);
        } else {
            $computer->reweight($edges);
        }
        $matching = $computer->match((count($remaining) & 1) === 0);
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
     * @return list<WeightedEdge>
     */
    private function constraintWeightedEdges(array $edges, DutchRoundGraph $graph, int $scoreLevel, array $selectedMdpIds): array
    {
        $selectedMdpSet = array_fill_keys($selectedMdpIds, true);
        $mdpIds = array_values(array_filter(
            array_keys($graph->playersById),
            static fn (int $playerId): bool => $graph->scoreRankByPlayerId[$playerId] > $scoreLevel,
        ));
        usort($mdpIds, static fn (int $left, int $right): int =>
            ($graph->scoreRankByPlayerId[$right] <=> $graph->scoreRankByPlayerId[$left])
            ?: ($graph->playersById[$left]->pairingNumber <=> $graph->playersById[$right]->pairingNumber)
        );

        return array_map(static function (WeightedEdge $edge) use ($graph, $scoreLevel, $selectedMdpSet, $mdpIds): WeightedEdge {
            $leftLevel = $graph->scoreRankByPlayerId[$edge->left];
            $rightLevel = $graph->scoreRankByPlayerId[$edge->right];
            $violations = [];
            foreach ($mdpIds as $playerId) {
                $incident = $edge->left === $playerId || $edge->right === $playerId;
                if (!$incident) {
                    $violations[] = 0;
                    continue;
                }
                $mustBeMatchedHere = isset($selectedMdpSet[$playerId]);
                $opponentLevel = $edge->left === $playerId
                    ? $rightLevel
                    : $leftLevel;
                $isMatchedHere = $opponentLevel === $scoreLevel;
                $violations[] = $isMatchedHere ? ($mustBeMatchedHere ? 1 : -1) : 0;
            }

            return new WeightedEdge($edge->left, $edge->right, [...$violations, ...$edge->weight]);
        }, $edges);
    }

    /**
     * BBP represents the PAB as the sole unmatched real vertex. Therefore a
     * desirable bye assignee has a low endpoint contribution: maximizing the
     * sum over matched edges leaves that endpoint out of the matching.
     *
     * @param list<WeightedEdge> $edges
     * @return list<WeightedEdge>
     */
    private function pabSelectionWeightedEdges(array $edges, TournamentState $state, DutchRoundGraph $graph, int $pabScoreLevel, bool $c9Applies): array
    {
        $rules = new \OpenPairing\Engine\Dutch\DutchRules();

        return array_map(static function (WeightedEdge $edge) use ($state, $graph, $pabScoreLevel, $c9Applies, $rules): WeightedEdge {
            $selection = 0;
            $c9 = 0;
            foreach ([$edge->left, $edge->right] as $playerId) {
                $player = $graph->playersById[$playerId];
                $candidate = $graph->scoreRankByPlayerId[$playerId] === $pabScoreLevel
                    && $rules->isEligibleForPairingAllocatedBye($state, $player);
                if (!$candidate) {
                    ++$selection;
                } elseif ($c9Applies) {
                    foreach ($player->results as $result) {
                        if (!$result['played']) {
                            ++$c9;
                        }
                    }
                }
            }

            return new WeightedEdge($edge->left, $edge->right, [$selection, $c9, ...$edge->weight]);
        }, $edges);
    }

    /** @return list<int> */
    private function selectedMdpIds(WeightedMatchingResult $matching, DutchRoundGraph $graph, int $scoreLevel): array
    {
        $mdpIds = [];
        foreach ($matching->pairs as $pair) {
            $leftMdp = $graph->scoreRankByPlayerId[$pair['left']] > $scoreLevel;
            $rightMdp = $graph->scoreRankByPlayerId[$pair['right']] > $scoreLevel;
            if ($leftMdp === $rightMdp) {
                continue;
            }
            $mdpId = $leftMdp ? $pair['left'] : $pair['right'];
            $opponentId = $leftMdp ? $pair['right'] : $pair['left'];
            if ($graph->scoreRankByPlayerId[$opponentId] !== $scoreLevel) {
                continue;
            }
            $mdpIds[] = $mdpId;
        }
        usort($mdpIds, static fn (int $left, int $right): int =>
            ($graph->scoreRankByPlayerId[$right] <=> $graph->scoreRankByPlayerId[$left])
            ?: ($graph->playersById[$left]->pairingNumber <=> $graph->playersById[$right]->pairingNumber)
        );

        return $mdpIds;
    }

    /** @return array{left: int, right: int}|null */
    private function pairForPlayer(WeightedMatchingResult $matching, int $playerId): ?array
    {
        foreach ($matching->pairs as $pair) {
            if ($pair['left'] === $playerId || $pair['right'] === $playerId) {
                return $pair;
            }
        }

        return null;
    }

    /**
     * @param array{left: int, right: int} $pair
     * @param list<int> $remaining
     * @param list<array{left: int, right: int}> $frozenPairs
     * @param list<DutchFreezeEvent> $freezeEvents
     */
    private function freezePair(
        array $pair,
        int $bracketIndex,
        int $scoreLevel,
        PersistentWeightedMatchingComputer $computer,
        array &$remaining,
        array &$frozenPairs,
        array &$freezeEvents,
    ): void {
        $remainingBefore = count($remaining);
        $frozenPairs[] = $pair;
        $removed = [$pair['left'] => true, $pair['right'] => true];
        $remaining = array_values(array_filter(
            $remaining,
            static fn (int $id): bool => !isset($removed[$id]),
        ));
        $freezeEvents[] = new DutchFreezeEvent(
            count($freezeEvents), $bracketIndex, $scoreLevel,
            min($pair['left'], $pair['right']), max($pair['left'], $pair['right']),
            $remainingBefore, count($remaining),
        );
        $computer->freeze($pair['left'], $pair['right']);
    }

    private function singleDownfloaterIsByeAssignee(DutchPreliminaryRoundResult $preliminary, DutchRoundGraph $graph): bool
    {
        $bye = $preliminary->unmatchedPlayerIds[0] ?? null;
        if ($bye === null) {
            return false;
        }
        $topLevel = max($graph->scoreRankByPlayerId ?: [0]);
        if ($graph->scoreRankByPlayerId[$bye] < $topLevel) {
            return false;
        }
        foreach ($preliminary->pairs as $pair) {
            $leftTop = $graph->scoreRankByPlayerId[$pair['left']] === $topLevel;
            $rightTop = $graph->scoreRankByPlayerId[$pair['right']] === $topLevel;
            if ($leftTop !== $rightTop) {
                return false;
            }
        }

        return true;
    }

}
