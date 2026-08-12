<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

/**
 * Persistent matching problem state with irreversible freezes and in-place
 * reweighting. This correctness-first implementation deliberately recomputes
 * the optimum from scratch after every mutation. A future warm-start solver may
 * replace that policy only after passing the same sequence differentials.
 */
final class PersistentWeightedMatchingComputer
{
    /** @var list<int> */
    private array $vertices;

    /** @var array<string, WeightedEdge> */
    private array $edgesByKey = [];

    /** @var list<array{left: int, right: int}> */
    private array $frozenPairs = [];

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
        return $this->matcher->match($this->vertices, $this->edges(), $requirePerfect);
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
        $this->frozenPairs[] = ['left' => $left, 'right' => $right];
        $removed = [$left => true, $right => true];
        $this->vertices = array_values(array_filter(
            $this->vertices,
            static fn (int $vertex): bool => !isset($removed[$vertex]),
        ));
        $this->edgesByKey = array_filter(
            $this->edgesByKey,
            static fn (WeightedEdge $edge): bool => !isset($removed[$edge->left]) && !isset($removed[$edge->right]),
        );
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
}
