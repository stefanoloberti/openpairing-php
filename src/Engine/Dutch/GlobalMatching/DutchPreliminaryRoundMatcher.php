<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchRules;
use OpenPairing\Engine\Matching\BlossomWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Engine\Matching\WeightedGeneralMatchingInterface;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;

/**
 * Whole-round BBP preliminary pass: proves completability and discovers the
 * PAB score group. C9 and bracket HE/HO refinement deliberately come later.
 */
final readonly class DutchPreliminaryRoundMatcher
{
    public function __construct(
        private DutchRoundGraphBuilder $graphBuilder = new DutchRoundGraphBuilder(),
        private WeightedGeneralMatchingInterface $matcher = new BlossomWeightedGeneralMatcher(),
        private DutchRules $rules = new DutchRules(),
    ) {
    }

    public function match(TournamentState $state): DutchPreliminaryRoundResult
    {
        $graph = $this->graphBuilder->build($state);
        $odd = (count($graph->vertices) & 1) === 1;
        $edges = [];
        foreach ($graph->compatibleEdges as $edge) {
            $left = $graph->playersById[$edge['left']];
            $right = $graph->playersById[$edge['right']];
            // With one unmatched vertex, maximizing the endpoint sums leaves
            // an eligible player in the lowest possible occupied score group.
            $edges[] = new WeightedEdge($left->id, $right->id, [
                ($this->rules->isEligibleForPairingAllocatedBye($state, $left) ? 0 : 1)
                    + ($this->rules->isEligibleForPairingAllocatedBye($state, $right) ? 0 : 1),
                $graph->scoreRankByPlayerId[$left->id] + $graph->scoreRankByPlayerId[$right->id],
            ]);
        }
        $matching = $this->matcher->match($graph->vertices, $edges, !$odd);
        $expectedUnmatched = $odd ? 1 : 0;
        if ($matching->status !== WeightedMatchingStatus::Optimal
            || count($matching->unmatched) !== $expectedUnmatched) {
            return new DutchPreliminaryRoundResult(
                WeightedMatchingStatus::NoMatching,
                [],
                $graph->vertices,
                null,
                $matching->statesExplored,
            );
        }

        $byeScore = null;
        if ($odd) {
            $bye = $graph->playersById[$matching->unmatched[0]];
            if (!$this->rules->isEligibleForPairingAllocatedBye($state, $bye)) {
                return new DutchPreliminaryRoundResult(
                    WeightedMatchingStatus::NoMatching,
                    [],
                    $graph->vertices,
                    null,
                    $matching->statesExplored,
                );
            }
            $byeScore = $bye->pairingScore();
        }

        return new DutchPreliminaryRoundResult(
            WeightedMatchingStatus::Optimal,
            $matching->pairs,
            $matching->unmatched,
            $byeScore,
            $matching->statesExplored,
        );
    }
}
