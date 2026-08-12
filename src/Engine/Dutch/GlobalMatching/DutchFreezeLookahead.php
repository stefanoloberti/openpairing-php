<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Engine\Matching\BlossomWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Engine\Matching\WeightedGeneralMatchingInterface;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;

/**
 * Selects a bracket edge only after optimizing the entire remaining graph.
 * This is the executable form of ADR 0008's no-locally-greedy-freeze rule.
 */
final readonly class DutchFreezeLookahead
{
    public function __construct(
        private WeightedGeneralMatchingInterface $matcher = new BlossomWeightedGeneralMatcher(),
    ) {
    }

    /**
     * @param list<int> $vertices
     * @param list<WeightedEdge> $edges
     * @param list<WeightedEdge> $candidates Edges eligible to be frozen now.
     */
    public function choose(array $vertices, array $edges, array $candidates): ?DutchFreezeDecision
    {
        if ($candidates === []) {
            return null;
        }
        $edgeByPair = [];
        foreach ($edges as $edge) {
            $edgeByPair[$this->key($edge->left, $edge->right)] = $edge;
        }

        $best = null;
        foreach ($candidates as $candidate) {
            $key = $this->key($candidate->left, $candidate->right);
            if (!isset($edgeByPair[$key]) || $edgeByPair[$key]->weight !== $candidate->weight) {
                throw new \InvalidArgumentException('Every freeze candidate must be an edge of the whole-round graph.');
            }
            $remainingVertices = array_values(array_filter(
                $vertices,
                static fn (int $vertex): bool => $vertex !== $candidate->left && $vertex !== $candidate->right,
            ));
            $remainingEdges = array_values(array_filter(
                $edges,
                static fn (WeightedEdge $edge): bool => $edge->left !== $candidate->left
                    && $edge->right !== $candidate->left
                    && $edge->left !== $candidate->right
                    && $edge->right !== $candidate->right,
            ));
            $suffix = $this->matcher->match($remainingVertices, $remainingEdges, true);
            if ($suffix->status !== WeightedMatchingStatus::Optimal) {
                continue;
            }
            $wholeRoundWeight = $candidate->weight;
            if (count($wholeRoundWeight) !== count($suffix->totalWeight)) {
                if ($remainingVertices !== []) {
                    throw new \InvalidArgumentException('Candidate and suffix weight dimensions differ.');
                }
            } else {
                foreach ($suffix->totalWeight as $index => $component) {
                    $wholeRoundWeight[$index] += $component;
                }
            }
            $decision = new DutchFreezeDecision($candidate, array_values($wholeRoundWeight), $suffix->statesExplored);
            if ($best === null || $this->compare($decision, $best) > 0) {
                $best = $decision;
            }
        }

        return $best;
    }

    private function compare(DutchFreezeDecision $left, DutchFreezeDecision $right): int
    {
        foreach ($left->wholeRoundWeight as $index => $component) {
            $comparison = $component <=> $right->wholeRoundWeight[$index];
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return (min($right->edge->left, $right->edge->right) <=> min($left->edge->left, $left->edge->right))
            ?: (max($right->edge->left, $right->edge->right) <=> max($left->edge->left, $left->edge->right));
    }

    private function key(int $left, int $right): string
    {
        return min($left, $right).':'.max($left, $right);
    }
}
