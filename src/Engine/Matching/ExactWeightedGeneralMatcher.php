<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

final class ExactWeightedGeneralMatcher implements WeightedGeneralMatchingInterface
{
    public const MAX_VERTICES = 22;
    // Dimensions affect only linear vector comparisons; the exponential guard
    // is the vertex count. Keep enough room for the complete Dutch vector plus
    // per-MDP lifecycle fields used by differential diagnostics.
    private const MAX_WEIGHT_DIMENSIONS = 512;
    private const MAX_ABSOLUTE_EDGE_WEIGHT = 1_000_000_000_000;

    /** @var list<int> */
    private array $vertices = [];

    /** @var array<int, array<int, list<int>>> */
    private array $weights = [];

    /** @var array<int, MatchingSolution|null> */
    private array $memo = [];

    private bool $requirePerfect = false;
    private int $dimensions = 0;
    private int $statesExplored = 0;

    public function match(array $vertices, array $edges, bool $requirePerfect = false): WeightedMatchingResult
    {
        if (count($vertices) > self::MAX_VERTICES) {
            throw new MatchingLimitExceeded(sprintf('Exact matcher supports at most %d vertices.', self::MAX_VERTICES));
        }
        if ($vertices === []) {
            return new WeightedMatchingResult(WeightedMatchingStatus::Optimal, [], [], [], 0, 1);
        }
        sort($vertices, SORT_NUMERIC);
        if (count($vertices) !== count(array_unique($vertices, SORT_REGULAR))) {
            throw new \InvalidArgumentException('Matching vertices must be unique.');
        }
        $indexByVertex = array_flip($vertices);
        $dimensions = null;
        $weights = [];
        foreach ($edges as $edge) {
            if ($edge->left === $edge->right || !isset($indexByVertex[$edge->left], $indexByVertex[$edge->right])) {
                throw new \InvalidArgumentException('Every edge must join two distinct known vertices.');
            }
            if ($edge->weight === [] || count($edge->weight) > self::MAX_WEIGHT_DIMENSIONS) {
                throw new \InvalidArgumentException(sprintf(
                    'Edge weight vectors must contain 1..%d integers.',
                    self::MAX_WEIGHT_DIMENSIONS,
                ));
            }
            $dimensions ??= count($edge->weight);
            if (count($edge->weight) !== $dimensions) {
                throw new \InvalidArgumentException('All edge weight vectors must have equal dimensions.');
            }
            foreach ($edge->weight as $component) {
                if (abs($component) > self::MAX_ABSOLUTE_EDGE_WEIGHT) {
                    throw new \InvalidArgumentException('An edge weight exceeds the exact supported range.');
                }
            }
            $leftIndex = $indexByVertex[min($edge->left, $edge->right)];
            $rightIndex = $indexByVertex[max($edge->left, $edge->right)];
            if (isset($weights[$leftIndex][$rightIndex])) {
                throw new \InvalidArgumentException('Parallel edges are not supported.');
            }
            $weights[$leftIndex][$rightIndex] = $edge->weight;
            $weights[$rightIndex][$leftIndex] = $edge->weight;
        }

        $this->vertices = $vertices;
        $this->weights = $weights;
        $this->memo = [];
        $this->requirePerfect = $requirePerfect;
        $this->dimensions = $dimensions ?? 0;
        $this->statesExplored = 0;
        if ($requirePerfect && count($vertices) % 2 === 1) {
            return new WeightedMatchingResult(WeightedMatchingStatus::NoMatching, [], $vertices, [], 0, 0);
        }
        $solution = $this->solve((1 << count($vertices)) - 1);
        if ($solution === null) {
            return new WeightedMatchingResult(WeightedMatchingStatus::NoMatching, [], $vertices, [], 0, $this->statesExplored);
        }

        return new WeightedMatchingResult(
            WeightedMatchingStatus::Optimal,
            $solution->pairs,
            $solution->unmatched,
            $solution->weight,
            $solution->cardinality(),
            $this->statesExplored,
        );
    }

    private function solve(int $mask): ?MatchingSolution
    {
        if ($mask === 0) {
            return new MatchingSolution([], [], array_fill(0, $this->dimensions, 0));
        }
        if (array_key_exists($mask, $this->memo)) {
            return $this->memo[$mask];
        }
        ++$this->statesExplored;
        $firstIndex = $this->lowestSetBitIndex($mask);
        $withoutFirst = $mask & ~(1 << $firstIndex);
        $best = null;
        if (!$this->requirePerfect) {
            $tail = $this->solve($withoutFirst);
            if ($tail !== null) {
                $unmatched = [...$tail->unmatched, $this->vertices[$firstIndex]];
                sort($unmatched, SORT_NUMERIC);
                $best = new MatchingSolution($tail->pairs, $unmatched, $tail->weight);
            }
        }
        for ($otherIndex = $firstIndex + 1, $count = count($this->vertices); $otherIndex < $count; ++$otherIndex) {
            if (($withoutFirst & (1 << $otherIndex)) === 0 || !isset($this->weights[$firstIndex][$otherIndex])) {
                continue;
            }
            $tail = $this->solve($withoutFirst & ~(1 << $otherIndex));
            if ($tail === null) {
                continue;
            }
            $pairs = [...$tail->pairs, [
                'left' => min($this->vertices[$firstIndex], $this->vertices[$otherIndex]),
                'right' => max($this->vertices[$firstIndex], $this->vertices[$otherIndex]),
            ]];
            usort($pairs, static fn (array $left, array $right): int => ($left['left'] <=> $right['left']) ?: ($left['right'] <=> $right['right']));
            $weight = [];
            foreach ($this->weights[$firstIndex][$otherIndex] as $dimension => $component) {
                $sum = $tail->weight[$dimension] + $component;
                if (abs($sum) > PHP_INT_MAX / 2) {
                    throw new \OverflowException('Matching weight sum exceeds the supported integer range.');
                }
                $weight[] = $sum;
            }
            $candidate = new MatchingSolution($pairs, $tail->unmatched, $weight);
            if ($best === null || $this->compare($candidate, $best) > 0) {
                $best = $candidate;
            }
        }

        return $this->memo[$mask] = $best;
    }

    private function compare(MatchingSolution $left, MatchingSolution $right): int
    {
        $cardinality = $left->cardinality() <=> $right->cardinality();
        if ($cardinality !== 0) {
            return $cardinality;
        }
        foreach ($left->weight as $index => $component) {
            $weight = $component <=> $right->weight[$index];
            if ($weight !== 0) {
                return $weight;
            }
        }
        $count = min(count($left->pairs), count($right->pairs));
        for ($index = 0; $index < $count; ++$index) {
            $first = $right->pairs[$index]['left'] <=> $left->pairs[$index]['left'];
            if ($first !== 0) {
                return $first;
            }
            $second = $right->pairs[$index]['right'] <=> $left->pairs[$index]['right'];
            if ($second !== 0) {
                return $second;
            }
        }

        return 0;
    }

    private function lowestSetBitIndex(int $mask): int
    {
        $index = 0;
        while (($mask & 1) === 0) {
            $mask >>= 1;
            ++$index;
        }

        return $index;
    }
}
