<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

/**
 * Polynomial primal-dual maximum-weight matching in a general graph.
 *
 * This is a PHP-specific, array-backed semantic port of the weighted Blossom
 * architecture in BBP Pairings src/matching/** at commit
 * 8f9e3c5ffdc4d7a08a33d31c6fb49b4e7acef4d5 (Apache-2.0). It preserves the
 * relevant invariants—top-level blossoms, outer/inner labels, exact dual
 * variables, zero-resistance edges, contraction, expansion and augmentation—
 * while replacing the C++ pool/pointer representation with stable integer
 * blossom identifiers. Modified for immutable arbitrary-width PHP weights.
 *
 * @internal
 */
final class BlossomMaximumWeight
{
    private const LABEL_OUTER = 1;
    private const LABEL_INNER = 2;

    private int $vertexCount = 0;
    private int $nextBlossom = 0;
    private int $operations = 0;

    /** @var array<int, list<int>> */
    private array $neighbors = [];
    /** @var array<string, DynamicUInt> */
    private array $weights = [];
    /** @var array<int, int> */
    private array $mate = [];
    /** @var array<int, int> */
    private array $label = [];
    /** @var array<int, array{0: int, 1: int}> */
    private array $labelEdge = [];
    /** @var array<int, int> */
    private array $inBlossom = [];
    /** @var array<int, int|null> */
    private array $blossomParent = [];
    /** @var array<int, int> */
    private array $blossomBase = [];
    /** @var array<int, array{0: int, 1: int}> */
    private array $bestEdge = [];
    /** @var array<int, DynamicUInt> */
    private array $dual = [];
    /** @var array<int, DynamicUInt> */
    private array $blossomDual = [];
    /** @var array<int, list<int>> */
    private array $children = [];
    /** @var array<int, list<array{0: int, 1: int}>> */
    private array $childEdges = [];
    /** @var array<string, true> */
    private array $allowed = [];
    /** @var list<int> */
    private array $queue = [];
    /** @var list<int> */
    private array $freeBlossomIds = [];
    private DynamicUInt $zero;
    private DynamicUInt $maxWeight;
    private bool $bestEdgesDirty = false;

    /**
     * @param list<EncodedWeightedEdge> $edges
     * @return array{mate: array<int, int>, operations: int}
     */
    public function solve(int $vertexCount, int $bitWidth, array $edges): array
    {
        $this->initialize($vertexCount, $bitWidth, $edges);
        while ($this->augmentStage()) {
            // Every successful stage increases cardinality by one.
        }
        ksort($this->mate, SORT_NUMERIC);

        return ['mate' => $this->mate, 'operations' => $this->operations];
    }

    /** @param list<EncodedWeightedEdge> $edges */
    private function initialize(int $vertexCount, int $bitWidth, array $edges): void
    {
        $this->vertexCount = $vertexCount;
        $this->nextBlossom = $vertexCount;
        $this->operations = 0;
        $this->neighbors = array_fill(0, $vertexCount, []);
        $this->weights = $this->mate = $this->label = $this->labelEdge = [];
        $this->inBlossom = $this->blossomParent = $this->blossomBase = [];
        $this->bestEdge = $this->dual = $this->blossomDual = [];
        $this->children = $this->childEdges = $this->allowed = [];
        $this->queue = $this->freeBlossomIds = [];
        $this->bestEdgesDirty = false;
        $this->zero = DynamicUInt::zero($bitWidth);
        $this->maxWeight = $this->zero;
        foreach ($edges as $edge) {
            if ($edge->left < 0 || $edge->right >= $vertexCount || $edge->left >= $edge->right) {
                throw new \InvalidArgumentException('Encoded Blossom edges must use ordered, in-range endpoints.');
            }
            $key = $this->edgeKey($edge->left, $edge->right);
            if (isset($this->weights[$key])) {
                throw new \InvalidArgumentException('Parallel Blossom edges are not supported.');
            }
            // BBP stores twice the caller weight internally.
            $weight = $edge->weight->shiftLeft(1);
            $this->weights[$key] = $weight;
            $this->neighbors[$edge->left][] = $edge->right;
            $this->neighbors[$edge->right][] = $edge->left;
            // Reduced costs are dual(u) + dual(v) - 2 * weight(u,v).
            // Therefore the feasible initial vertex dual is the caller edge
            // weight, not the doubled representation stored above.
            if ($edge->weight->compare($this->maxWeight) > 0) {
                $this->maxWeight = $edge->weight;
            }
        }
        foreach ($this->neighbors as &$neighbors) {
            sort($neighbors, SORT_NUMERIC);
        }
        unset($neighbors);
        for ($vertex = 0; $vertex < $vertexCount; ++$vertex) {
            $this->inBlossom[$vertex] = $vertex;
            $this->blossomParent[$vertex] = null;
            $this->blossomBase[$vertex] = $vertex;
            $this->dual[$vertex] = $this->maxWeight;
        }
    }

    private function augmentStage(): bool
    {
        $this->label = $this->labelEdge = $this->bestEdge = $this->allowed = [];
        $this->queue = [];
        for ($vertex = 0; $vertex < $this->vertexCount; ++$vertex) {
            if (!isset($this->mate[$vertex])) {
                $this->assignLabel($vertex, self::LABEL_OUTER, null);
            }
        }

        $augmented = false;
        while (true) {
            while ($this->queue !== [] && !$augmented) {
                $vertex = array_pop($this->queue);
                if (($this->label[$this->inBlossom[$vertex]] ?? null) !== self::LABEL_OUTER) {
                    continue;
                }
                foreach ($this->neighbors[$vertex] as $neighbor) {
                    ++$this->operations;
                    $leftBlossom = $this->inBlossom[$vertex];
                    $rightBlossom = $this->inBlossom[$neighbor];
                    if ($leftBlossom === $rightBlossom) {
                        continue;
                    }
                    $edge = [$vertex, $neighbor];
                    $key = $this->edgeKey($vertex, $neighbor);
                    $slack = null;
                    if (!isset($this->allowed[$key])) {
                        $slack = $this->slack($edge);
                        if ($slack->isZero()) {
                            $this->allowed[$key] = true;
                        }
                    }
                    if (isset($this->allowed[$key])) {
                        if (!isset($this->label[$rightBlossom])) {
                            $this->assignLabel($neighbor, self::LABEL_INNER, $vertex);
                        } elseif ($this->label[$rightBlossom] === self::LABEL_OUTER) {
                            $base = $this->scanBlossom($vertex, $neighbor);
                            if ($base !== null) {
                                $this->addBlossom($base, $vertex, $neighbor);
                            } else {
                                $this->augmentMatching($vertex, $neighbor);
                                $augmented = true;
                                break;
                            }
                        } elseif (!isset($this->label[$neighbor])) {
                            $this->label[$neighbor] = self::LABEL_INNER;
                            $this->labelEdge[$neighbor] = [$vertex, $neighbor];
                        }
                    } elseif (($this->label[$rightBlossom] ?? null) === self::LABEL_OUTER) {
                        $this->updateBestEdge($leftBlossom, $edge, $slack);
                    } elseif (!isset($this->label[$neighbor])) {
                        $this->updateBestEdge($neighbor, $edge, $slack);
                    }
                }
            }
            if ($augmented) {
                break;
            }

            [$deltaType, $delta, $deltaEdge, $deltaBlossom] = $this->minimumDualAdjustment();
            for ($vertex = 0; $vertex < $this->vertexCount; ++$vertex) {
                $top = $this->inBlossom[$vertex];
                if (($this->label[$top] ?? null) === self::LABEL_OUTER) {
                    $this->dual[$vertex] = $this->dual[$vertex]->subtract($delta);
                } elseif (($this->label[$top] ?? null) === self::LABEL_INNER) {
                    $this->dual[$vertex] = $this->dual[$vertex]->add($delta);
                }
            }
            foreach (array_keys($this->children) as $blossom) {
                if ($this->blossomParent[$blossom] !== null) {
                    continue;
                }
                if (($this->label[$blossom] ?? null) === self::LABEL_OUTER) {
                    $this->blossomDual[$blossom] = $this->blossomDual[$blossom]->add($delta);
                } elseif (($this->label[$blossom] ?? null) === self::LABEL_INNER) {
                    $this->blossomDual[$blossom] = $this->blossomDual[$blossom]->subtract($delta);
                }
            }

            if ($deltaType === 1) {
                break;
            }
            if ($deltaType === 2 || $deltaType === 3) {
                if ($deltaEdge === null) {
                    throw new \LogicException('Blossom dual adjustment omitted its edge.');
                }
                $this->allowed[$this->edgeKey($deltaEdge[0], $deltaEdge[1])] = true;
                $this->queue[] = $deltaEdge[0];
            } elseif ($deltaType === 4) {
                if ($deltaBlossom === null) {
                    throw new \LogicException('Blossom dual adjustment omitted its blossom.');
                }
                $this->expandBlossom($deltaBlossom, false);
            }
        }

        if ($augmented) {
            foreach ($this->mate as $vertex => $neighbor) {
                if (($this->mate[$neighbor] ?? null) !== $vertex) {
                    throw new \LogicException('Blossom augmentation produced an asymmetric matching.');
                }
            }
        }
        foreach (array_keys($this->children) as $blossom) {
            if (($this->blossomParent[$blossom] ?? null) === null
                && ($this->label[$blossom] ?? null) === self::LABEL_OUTER
                && $this->blossomDual[$blossom]->isZero()) {
                $this->expandBlossom($blossom, true);
            }
        }

        return $augmented;
    }

    private function assignLabel(int $vertex, int $label, ?int $from): void
    {
        $blossom = $this->inBlossom[$vertex];
        if (isset($this->label[$vertex]) || isset($this->label[$blossom])) {
            throw new \LogicException('Blossom vertex was labeled twice.');
        }
        $this->label[$vertex] = $this->label[$blossom] = $label;
        if ($from !== null) {
            $this->labelEdge[$vertex] = $this->labelEdge[$blossom] = [$from, $vertex];
        }
        unset($this->bestEdge[$vertex], $this->bestEdge[$blossom]);
        if ($label === self::LABEL_OUTER) {
            foreach ($this->leaves($blossom) as $leaf) {
                $this->queue[] = $leaf;
            }
        } else {
            $base = $this->blossomBase[$blossom];
            if (!isset($this->mate[$base])) {
                throw new \LogicException('An inner blossom base must be matched.');
            }
            $this->assignLabel($this->mate[$base], self::LABEL_OUTER, $base);
        }
    }

    private function scanBlossom(int $left, int $right): ?int
    {
        $path = [];
        $base = null;
        while ($left !== -1) {
            $blossom = $this->inBlossom[$left];
            if ((($this->label[$blossom] ?? 0) & 4) !== 0) {
                $base = $this->blossomBase[$blossom];
                break;
            }
            $path[] = $blossom;
            $this->label[$blossom] = self::LABEL_OUTER | 4;
            if (!isset($this->labelEdge[$blossom])) {
                $left = -1;
            } else {
                $left = $this->labelEdge[$blossom][0];
                $blossom = $this->inBlossom[$left];
                $left = $this->labelEdge[$blossom][0];
            }
            if ($right !== -1) {
                [$left, $right] = [$right, $left];
            }
        }
        foreach ($path as $blossom) {
            $this->label[$blossom] = self::LABEL_OUTER;
        }

        return $base;
    }

    private function addBlossom(int $base, int $left, int $right): void
    {
        $baseBlossom = $this->inBlossom[$base];
        $leftBlossom = $this->inBlossom[$left];
        $rightBlossom = $this->inBlossom[$right];
        $blossom = $this->allocateBlossom();
        $path = [];
        $edges = [[$left, $right]];
        while ($leftBlossom !== $baseBlossom) {
            $this->blossomParent[$leftBlossom] = $blossom;
            $path[] = $leftBlossom;
            $edges[] = $this->labelEdge[$leftBlossom];
            $left = $this->labelEdge[$leftBlossom][0];
            $leftBlossom = $this->inBlossom[$left];
        }
        $path[] = $baseBlossom;
        $path = array_reverse($path);
        $edges = array_reverse($edges);
        while ($rightBlossom !== $baseBlossom) {
            $this->blossomParent[$rightBlossom] = $blossom;
            $path[] = $rightBlossom;
            $edge = $this->labelEdge[$rightBlossom];
            $edges[] = [$edge[1], $edge[0]];
            $right = $edge[0];
            $rightBlossom = $this->inBlossom[$right];
        }
        $this->blossomParent[$baseBlossom] = $blossom;
        $this->children[$blossom] = $path;
        $this->childEdges[$blossom] = $edges;
        $this->blossomBase[$blossom] = $base;
        $this->blossomParent[$blossom] = null;
        $this->blossomDual[$blossom] = $this->zero;
        $this->label[$blossom] = self::LABEL_OUTER;
        if (isset($this->labelEdge[$baseBlossom])) {
            $this->labelEdge[$blossom] = $this->labelEdge[$baseBlossom];
        }
        foreach ($this->leaves($blossom) as $leaf) {
            if (($this->label[$this->inBlossom[$leaf]] ?? null) === self::LABEL_OUTER) {
                $this->queue[] = $leaf;
            }
            $this->inBlossom[$leaf] = $blossom;
        }
        $this->recomputeBestEdge($blossom);
        $this->bestEdgesDirty = true;
    }

    private function expandBlossom(int $blossom, bool $endStage): void
    {
        foreach ($this->children[$blossom] as $child) {
            $this->blossomParent[$child] = null;
            if ($this->isBlossom($child)) {
                if ($endStage && $this->blossomDual[$child]->isZero()) {
                    $this->expandBlossom($child, true);
                } else {
                    foreach ($this->leaves($child) as $leaf) {
                        $this->inBlossom[$leaf] = $child;
                    }
                }
            } else {
                $this->inBlossom[$child] = $child;
            }
        }

        if (!$endStage && ($this->label[$blossom] ?? null) === self::LABEL_INNER) {
            $entryChild = $this->inBlossom[$this->labelEdge[$blossom][1]];
            $count = count($this->children[$blossom]);
            $index = array_search($entryChild, $this->children[$blossom], true);
            if ($index === false) {
                throw new \LogicException('Inner blossom entry child is missing.');
            }
            if (($index & 1) === 1) {
                $index -= $count;
                $step = 1;
            } else {
                $step = -1;
            }
            [$vertex, $neighbor] = $this->labelEdge[$blossom];
            while ($index !== 0) {
                if ($step === 1) {
                    [$pairLeft, $pairRight] = $this->edgeAt($blossom, $index);
                } else {
                    [$pairRight, $pairLeft] = $this->edgeAt($blossom, $index - 1);
                }
                unset($this->label[$neighbor], $this->label[$pairRight]);
                $this->assignLabel($neighbor, self::LABEL_INNER, $vertex);
                $this->allowed[$this->edgeKey($pairLeft, $pairRight)] = true;
                $index += $step;
                if ($step === 1) {
                    [$vertex, $neighbor] = $this->edgeAt($blossom, $index);
                } else {
                    [$neighbor, $vertex] = $this->edgeAt($blossom, $index - 1);
                }
                $this->allowed[$this->edgeKey($vertex, $neighbor)] = true;
                $index += $step;
            }
            $child = $this->childAt($blossom, $index);
            $this->label[$neighbor] = $this->label[$child] = self::LABEL_INNER;
            $this->labelEdge[$neighbor] = $this->labelEdge[$child] = [$vertex, $neighbor];
            unset($this->bestEdge[$child]);
            $index += $step;
            while ($this->childAt($blossom, $index) !== $entryChild) {
                $child = $this->childAt($blossom, $index);
                if (($this->label[$child] ?? null) !== self::LABEL_OUTER) {
                    $reachable = null;
                    foreach ($this->leaves($child) as $leaf) {
                        if (isset($this->label[$leaf])) {
                            $reachable = $leaf;
                            break;
                        }
                    }
                    if ($reachable !== null) {
                        $from = $this->labelEdge[$reachable][0];
                        unset($this->label[$reachable], $this->label[$this->mate[$this->blossomBase[$child]]]);
                        $this->assignLabel($reachable, self::LABEL_INNER, $from);
                    }
                }
                $index += $step;
            }
        }

        unset(
            $this->label[$blossom],
            $this->labelEdge[$blossom],
            $this->bestEdge[$blossom],
            $this->blossomParent[$blossom],
            $this->blossomBase[$blossom],
            $this->blossomDual[$blossom],
            $this->children[$blossom],
            $this->childEdges[$blossom],
        );
        $this->freeBlossomIds[] = $blossom;
        $this->bestEdgesDirty = true;
    }

    private function augmentBlossom(int $blossom, int $vertex): void
    {
        $child = $vertex;
        while ($this->blossomParent[$child] !== $blossom) {
            $parent = $this->blossomParent[$child] ?? null;
            if ($parent === null) {
                throw new \LogicException('Augmentation vertex is outside the requested blossom.');
            }
            $child = $parent;
        }
        if ($this->isBlossom($child)) {
            $this->augmentBlossom($child, $vertex);
        }
        $count = count($this->children[$blossom]);
        $index = array_search($child, $this->children[$blossom], true);
        if ($index === false) {
            throw new \LogicException('Augmentation child is missing from blossom.');
        }
        if (($index & 1) === 1) {
            $cursor = $index - $count;
            $step = 1;
        } else {
            $cursor = $index;
            $step = -1;
        }
        while ($cursor !== 0) {
            $cursor += $step;
            $child = $this->childAt($blossom, $cursor);
            if ($step === 1) {
                [$left, $right] = $this->edgeAt($blossom, $cursor);
            } else {
                [$right, $left] = $this->edgeAt($blossom, $cursor - 1);
            }
            if ($this->isBlossom($child)) {
                $this->augmentBlossom($child, $left);
            }
            $cursor += $step;
            $child = $this->childAt($blossom, $cursor);
            if ($this->isBlossom($child)) {
                $this->augmentBlossom($child, $right);
            }
            $this->mate[$left] = $right;
            $this->mate[$right] = $left;
        }
        $this->children[$blossom] = [
            ...array_slice($this->children[$blossom], $index),
            ...array_slice($this->children[$blossom], 0, $index),
        ];
        $this->childEdges[$blossom] = [
            ...array_slice($this->childEdges[$blossom], $index),
            ...array_slice($this->childEdges[$blossom], 0, $index),
        ];
        $this->blossomBase[$blossom] = $this->blossomBase[$this->children[$blossom][0]];
    }

    private function augmentMatching(int $left, int $right): void
    {
        foreach ([[$left, $right], [$right, $left]] as [$vertex, $newMate]) {
            while (true) {
                $outer = $this->inBlossom[$vertex];
                if ($this->isBlossom($outer)) {
                    $this->augmentBlossom($outer, $vertex);
                }
                $this->mate[$vertex] = $newMate;
                if (!isset($this->labelEdge[$outer])) {
                    break;
                }
                $innerVertex = $this->labelEdge[$outer][0];
                $inner = $this->inBlossom[$innerVertex];
                [$vertex, $newMate] = $this->labelEdge[$inner];
                if ($this->isBlossom($inner)) {
                    $this->augmentBlossom($inner, $newMate);
                }
                $this->mate[$newMate] = $vertex;
            }
        }
    }

    /**
     * @return array{0: int, 1: DynamicUInt, 2: array{0: int, 1: int}|null, 3: int|null}
     */
    private function minimumDualAdjustment(): array
    {
        if ($this->bestEdgesDirty) {
            $this->recomputeStageBestEdges();
            $this->bestEdgesDirty = false;
        }
        $type = 1;
        $delta = null;
        $edge = null;
        $blossomResult = null;
        // With maximum-cardinality semantics delta1 must not terminate an
        // intermediate stage. It is only the fallback once no augmenting path
        // exists, as in Galil/NetworkX maxcardinality mode.
        for ($vertex = 0; $vertex < $this->vertexCount; ++$vertex) {
            if ($delta === null || $this->dual[$vertex]->compare($delta) < 0) {
                $delta = $this->dual[$vertex];
            }
        }
        if ($delta === null) {
            return [1, $this->zero, null, null];
        }
        $type = -1;
        for ($vertex = 0; $vertex < $this->vertexCount; ++$vertex) {
            if (!isset($this->label[$this->inBlossom[$vertex]]) && isset($this->bestEdge[$vertex])) {
                $candidate = $this->slack($this->bestEdge[$vertex]);
                if ($type === -1 || $candidate->compare($delta) < 0) {
                    $delta = $candidate;
                    $type = 2;
                    $edge = $this->bestEdge[$vertex];
                }
            }
        }
        foreach ($this->topLevelBlossoms() as $blossom) {
            if (($this->label[$blossom] ?? null) === self::LABEL_OUTER && isset($this->bestEdge[$blossom])) {
                $candidate = $this->slack($this->bestEdge[$blossom])->shiftRight(1);
                if ($type === -1 || $candidate->compare($delta) < 0) {
                    $delta = $candidate;
                    $type = 3;
                    $edge = $this->bestEdge[$blossom];
                }
            }
        }
        foreach (array_keys($this->children) as $blossom) {
            if ($this->blossomParent[$blossom] === null
                && ($this->label[$blossom] ?? null) === self::LABEL_INNER
                && ($type === -1 || $this->blossomDual[$blossom]->compare($delta) < 0)) {
                $delta = $this->blossomDual[$blossom];
                $type = 4;
                $blossomResult = $blossom;
            }
        }

        return $type === -1 ? [1, $delta, null, null] : [$type, $delta, $edge, $blossomResult];
    }

    /**
     * Rebuild delta2/delta3 candidates from the current top-level blossom
     * partition. This intentionally favours correctness over caching: a
     * best-edge cached before a contraction can become internal afterwards.
     */
    private function recomputeStageBestEdges(): void
    {
        $this->bestEdge = [];
        for ($vertex = 0; $vertex < $this->vertexCount; ++$vertex) {
            $leftBlossom = $this->inBlossom[$vertex];
            if (($this->label[$leftBlossom] ?? null) !== self::LABEL_OUTER) {
                continue;
            }
            foreach ($this->neighbors[$vertex] as $neighbor) {
                $rightBlossom = $this->inBlossom[$neighbor];
                if ($leftBlossom === $rightBlossom) {
                    continue;
                }
                $edge = [$vertex, $neighbor];
                if (!isset($this->label[$rightBlossom])) {
                    $this->updateBestEdge($neighbor, $edge);
                } elseif ($this->label[$rightBlossom] === self::LABEL_OUTER) {
                    $this->updateBestEdge($leftBlossom, $edge);
                }
            }
        }
    }

    /** @param array{0: int, 1: int} $edge */
    private function slack(array $edge): DynamicUInt
    {
        return $this->dual[$edge[0]]
            ->add($this->dual[$edge[1]])
            ->subtract($this->weights[$this->edgeKey($edge[0], $edge[1])]);
    }

    /** @param array{0: int, 1: int} $edge */
    private function updateBestEdge(int $owner, array $edge, ?DynamicUInt $knownSlack = null): void
    {
        if (!isset($this->bestEdge[$owner])) {
            $this->bestEdge[$owner] = $edge;

            return;
        }
        $candidateSlack = $knownSlack ?? $this->slack($edge);
        $comparison = $candidateSlack->compare($this->slack($this->bestEdge[$owner]));
        if ($comparison < 0 || ($comparison === 0 && $this->compareEdges($edge, $this->bestEdge[$owner]) < 0)) {
            $this->bestEdge[$owner] = $edge;
        }
    }

    private function recomputeBestEdge(int $blossom): void
    {
        unset($this->bestEdge[$blossom]);
        foreach ($this->leaves($blossom) as $leaf) {
            foreach ($this->neighbors[$leaf] as $neighbor) {
                $other = $this->inBlossom[$neighbor];
                if ($other !== $blossom && ($this->label[$other] ?? null) === self::LABEL_OUTER) {
                    $this->updateBestEdge($blossom, [$leaf, $neighbor]);
                }
            }
        }
        foreach ($this->children[$blossom] as $child) {
            unset($this->bestEdge[$child]);
        }
    }

    /** @return list<int> */
    private function leaves(int $blossom): array
    {
        if (!$this->isBlossom($blossom)) {
            return [$blossom];
        }
        $result = [];
        foreach ($this->children[$blossom] as $child) {
            array_push($result, ...$this->leaves($child));
        }

        return $result;
    }

    /** @return list<int> */
    private function topLevelBlossoms(): array
    {
        $result = [];
        for ($vertex = 0; $vertex < $this->vertexCount; ++$vertex) {
            if ($this->blossomParent[$vertex] === null) {
                $result[] = $vertex;
            }
        }
        foreach (array_keys($this->children) as $blossom) {
            if ($this->blossomParent[$blossom] === null) {
                $result[] = $blossom;
            }
        }

        return $result;
    }

    private function allocateBlossom(): int
    {
        if ($this->freeBlossomIds !== []) {
            sort($this->freeBlossomIds, SORT_NUMERIC);

            return array_shift($this->freeBlossomIds);
        }

        return $this->nextBlossom++;
    }

    private function isBlossom(int $id): bool
    {
        return isset($this->children[$id]);
    }

    private function childAt(int $blossom, int $index): int
    {
        $children = $this->children[$blossom];

        return $children[$this->mod($index, count($children))];
    }

    /** @return array{0: int, 1: int} */
    private function edgeAt(int $blossom, int $index): array
    {
        $edges = $this->childEdges[$blossom];

        return $edges[$this->mod($index, count($edges))];
    }

    private function mod(int $value, int $modulus): int
    {
        $result = $value % $modulus;

        return $result < 0 ? $result + $modulus : $result;
    }

    private function edgeKey(int $left, int $right): string
    {
        return min($left, $right).':'.max($left, $right);
    }

    /**
     * @param array{0: int, 1: int} $left
     * @param array{0: int, 1: int} $right
     */
    private function compareEdges(array $left, array $right): int
    {
        $left = [min(...$left), max(...$left)];
        $right = [min(...$right), max(...$right)];

        return ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1]);
    }
}
