<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

/** Scalable O(V^3) exact weighted matching for non-bipartite graphs. */
final class BlossomWeightedGeneralMatcher implements WeightedGeneralMatchingInterface
{
    public function __construct(
        private readonly LexicographicWeightEncoder $encoder = new LexicographicWeightEncoder(),
    ) {
    }

    public function match(array $vertices, array $edges, bool $requirePerfect = false): WeightedMatchingResult
    {
        if ($vertices === []) {
            return new WeightedMatchingResult(WeightedMatchingStatus::Optimal, [], [], [], 0, 0);
        }
        sort($vertices, SORT_NUMERIC);
        if (count($vertices) !== count(array_unique($vertices, SORT_REGULAR))) {
            throw new \InvalidArgumentException('Matching vertices must be unique.');
        }
        if ($requirePerfect && (count($vertices) & 1) === 1) {
            return new WeightedMatchingResult(WeightedMatchingStatus::NoMatching, [], $vertices, [], 0, 0);
        }
        $indexByVertex = array_flip($vertices);
        $dimensions = null;
        $edgeWeights = [];
        $indexedEdges = [];
        foreach ($edges as $edge) {
            if ($edge->left === $edge->right || !isset($indexByVertex[$edge->left], $indexByVertex[$edge->right])) {
                throw new \InvalidArgumentException('Every edge must join two distinct known vertices.');
            }
            if ($edge->weight === []) {
                throw new \InvalidArgumentException('Edge weight vectors cannot be empty.');
            }
            $dimensions ??= count($edge->weight);
            if (count($edge->weight) !== $dimensions) {
                throw new \InvalidArgumentException('All edge weight vectors must have equal dimensions.');
            }
            $left = min($edge->left, $edge->right);
            $right = max($edge->left, $edge->right);
            $key = $left.':'.$right;
            if (isset($edgeWeights[$key])) {
                throw new \InvalidArgumentException('Parallel edges are not supported.');
            }
            $edgeWeights[$key] = $edge->weight;
            $indexedEdges[] = new WeightedEdge(
                $indexByVertex[$left],
                $indexByVertex[$right],
                $edge->weight,
            );
        }

        $problem = $this->encoder->encode(range(0, count($vertices) - 1), $indexedEdges);
        $solution = (new BlossomMaximumWeight())->solve(count($vertices), $problem->bitWidth, $problem->edges);
        $pairs = [];
        $used = [];
        foreach ($solution['mate'] as $leftIndex => $rightIndex) {
            if (isset($used[$leftIndex], $used[$rightIndex])) {
                continue;
            }
            $left = min($vertices[$leftIndex], $vertices[$rightIndex]);
            $right = max($vertices[$leftIndex], $vertices[$rightIndex]);
            $pairs[] = ['left' => $left, 'right' => $right];
            $used[$leftIndex] = $used[$rightIndex] = true;
        }
        usort($pairs, static fn (array $left, array $right): int =>
            ($left['left'] <=> $right['left']) ?: ($left['right'] <=> $right['right'])
        );
        $unmatched = array_values(array_filter(
            $vertices,
            static fn (int $vertex, int $index): bool => !isset($used[$index]),
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($requirePerfect && $unmatched !== []) {
            return new WeightedMatchingResult(
                WeightedMatchingStatus::NoMatching,
                [],
                $vertices,
                [],
                0,
                $solution['operations'],
            );
        }
        $totalWeight = array_fill(0, $dimensions ?? 0, 0);
        foreach ($pairs as $pair) {
            $weight = $edgeWeights[$pair['left'].':'.$pair['right']];
            foreach ($weight as $dimension => $component) {
                $totalWeight[$dimension] += $component;
            }
        }
        $totalWeight = array_values($totalWeight);

        return new WeightedMatchingResult(
            WeightedMatchingStatus::Optimal,
            $pairs,
            $unmatched,
            $totalWeight,
            count($pairs),
            $solution['operations'],
        );
    }
}
