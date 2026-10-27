<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

/**
 * Persistent matching problem state with irreversible freezes and in-place
 * reweighting. A frozen edge that belongs to the current optimum leaves an
 * optimal residual while weights remain identical: any better residual would
 * improve the original matching. Every changed weight or non-matched freeze
 * invalidates that proof and recomputes from scratch.
 */
final class PersistentWeightedMatchingComputer
{
    /** @var list<int> */
    private array $vertices;

    /** @var array<string, WeightedEdge> */
    private array $edgesByKey = [];

    /** @var list<array{left: int, right: int}> */
    private array $frozenPairs = [];

    private ?WeightedMatchingResult $cachedMatching = null;
    private ?bool $cachedRequirePerfect = null;

    /**
     * @param list<int> $vertices
     * @param list<WeightedEdge> $edges
     */
    public function __construct(
        array $vertices,
        array $edges,
        private readonly WeightedGeneralMatchingInterface $matcher = new BlossomWeightedGeneralMatcher(),
    ) {
        sort($vertices, SORT_NUMERIC);
        if (count($vertices) !== count(array_unique($vertices, SORT_REGULAR))) {
            throw new \InvalidArgumentException('Persistent matching vertices must be unique.');
        }
        $this->vertices = $vertices;
        $this->edgesByKey = $this->indexEdges($edges, $vertices);
    }

    public function match(bool $requirePerfect = true): WeightedMatchingResult
    {
        if ($this->cachedMatching !== null && $this->cachedRequirePerfect === $requirePerfect) {
            return $this->cachedMatching;
        }

        $this->cachedMatching = $this->matcher->match($this->vertices, $this->edges(), $requirePerfect);
        $this->cachedRequirePerfect = $requirePerfect;

        return $this->cachedMatching;
    }

    public function freeze(int $left, int $right): void
    {
        [$left, $right] = [min($left, $right), max($left, $right)];
        if ($left === $right || !in_array($left, $this->vertices, true) || !in_array($right, $this->vertices, true)) {
            throw new \InvalidArgumentException('A frozen edge must join two active distinct vertices.');
        }
        if (!isset($this->edgesByKey[self::key($left, $right)])) {
            throw new \InvalidArgumentException('A frozen edge must exist in the active graph.');
        }
        $frozen = ['left' => $left, 'right' => $right];
        $canRetain = $this->cachedMatching !== null && in_array($frozen, $this->cachedMatching->pairs, true);
        $this->frozenPairs[] = $frozen;
        $removed = [$left => true, $right => true];
        $this->vertices = array_values(array_filter(
            $this->vertices,
            static fn (int $vertex): bool => !isset($removed[$vertex]),
        ));
        $this->edgesByKey = array_filter(
            $this->edgesByKey,
            static fn (WeightedEdge $edge): bool => !isset($removed[$edge->left]) && !isset($removed[$edge->right]),
        );
        if ($canRetain) {
            $pairs = array_values(array_filter(
                $this->cachedMatching->pairs,
                static fn (array $pair): bool => $pair !== $frozen,
            ));
            $unmatched = array_values(array_filter(
                $this->cachedMatching->unmatched,
                static fn (int $vertex): bool => $vertex !== $left && $vertex !== $right,
            ));
            $totalWeight = $this->totalWeight($pairs);
            $this->cachedMatching = new WeightedMatchingResult(
                WeightedMatchingStatus::Optimal,
                $pairs,
                $unmatched,
                $totalWeight,
                count($pairs),
                0,
            );
        } else {
            $this->cachedMatching = null;
            $this->cachedRequirePerfect = null;
        }
    }

    /**
     * Updates every active edge weight without changing graph topology.
     *
     * @param list<WeightedEdge> $edges
     */
    public function reweight(array $edges): void
    {
        $replacement = $this->indexEdges($edges, $this->vertices);
        $oldKeys = array_keys($this->edgesByKey);
        $newKeys = array_keys($replacement);
        sort($oldKeys, SORT_STRING);
        sort($newKeys, SORT_STRING);
        if ($oldKeys !== $newKeys) {
            throw new \InvalidArgumentException('Reweighting must preserve every active edge and cannot add edges.');
        }
        if (!$this->sameWeights($this->edgesByKey, $replacement)) {
            $this->cachedMatching = null;
            $this->cachedRequirePerfect = null;
        }
        $this->edgesByKey = $replacement;
    }

    /** @return list<int> */
    public function vertices(): array
    {
        return $this->vertices;
    }

    /** @return list<WeightedEdge> */
    public function edges(): array
    {
        $edges = array_values($this->edgesByKey);
        usort($edges, static fn (WeightedEdge $left, WeightedEdge $right): int =>
            ($left->left <=> $right->left) ?: ($left->right <=> $right->right)
        );

        return $edges;
    }

    /** @return list<array{left: int, right: int}> */
    public function frozenPairs(): array
    {
        return $this->frozenPairs;
    }

    /**
     * @param list<WeightedEdge> $edges
     * @param list<int> $vertices
     * @return array<string, WeightedEdge>
     */
    private function indexEdges(array $edges, array $vertices): array
    {
        $known = array_fill_keys($vertices, true);
        $indexed = [];
        foreach ($edges as $edge) {
            $left = min($edge->left, $edge->right);
            $right = max($edge->left, $edge->right);
            if ($left === $right || !isset($known[$left], $known[$right])) {
                throw new \InvalidArgumentException('Every persistent edge must join two active distinct vertices.');
            }
            $key = self::key($left, $right);
            if (isset($indexed[$key])) {
                throw new \InvalidArgumentException('Parallel persistent edges are not supported.');
            }
            $indexed[$key] = new WeightedEdge($left, $right, $edge->weight);
        }

        return $indexed;
    }

    private static function key(int $left, int $right): string
    {
        return min($left, $right).':'.max($left, $right);
    }

    /**
     * @param array<string, WeightedEdge> $left
     * @param array<string, WeightedEdge> $right
     */
    private function sameWeights(array $left, array $right): bool
    {
        foreach ($left as $key => $edge) {
            if (!isset($right[$key]) || $edge->weight !== $right[$key]->weight) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{left: int, right: int}> $pairs
     * @return list<int>
     */
    private function totalWeight(array $pairs): array
    {
        if ($pairs === []) {
            $first = reset($this->edgesByKey);

            return $first instanceof WeightedEdge ? array_fill(0, count($first->weight), 0) : [];
        }
        $first = $this->edgesByKey[self::key($pairs[0]['left'], $pairs[0]['right'])];
        $total = array_fill(0, count($first->weight), 0);
        foreach ($pairs as $pair) {
            $weight = $this->edgesByKey[self::key($pair['left'], $pair['right'])]->weight;
            foreach ($weight as $index => $component) {
                $total[$index] += $component;
            }
        }

        return array_values($total);
    }
}
