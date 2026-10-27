<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

/** Packs vector weights into exact unsigned scalars suitable for Blossom. */
final class LexicographicWeightEncoder
{
    public function __construct(private readonly bool $includeMateVector = true)
    {
    }

    /**
     * @param list<int> $vertices
     * @param list<WeightedEdge> $edges
     */
    public function encode(array $vertices, array $edges): EncodedMatchingProblem
    {
        if ($edges === []) {
            return new EncodedMatchingProblem(128, []);
        }
        $dimensions = count($edges[0]->weight);
        if ($dimensions < 1) {
            throw new \InvalidArgumentException('Edge weight vectors cannot be empty.');
        }
        $minimum = array_fill(0, $dimensions, PHP_INT_MAX);
        $maximum = array_fill(0, $dimensions, PHP_INT_MIN);
        $seen = [];
        foreach ($edges as $edge) {
            if (count($edge->weight) !== $dimensions) {
                throw new \InvalidArgumentException('All edge weight vectors must have equal dimensions.');
            }
            $key = min($edge->left, $edge->right).'-'.max($edge->left, $edge->right);
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('Parallel edges are not supported.');
            }
            $seen[$key] = true;
            foreach ($edge->weight as $index => $component) {
                $minimum[$index] = min($minimum[$index], $component);
                $maximum[$index] = max($maximum[$index], $component);
            }
        }

        $maxCardinality = intdiv(count($vertices), 2);
        $fieldWidths = [$this->bitsFor($maxCardinality)];
        foreach ($minimum as $index => $componentMinimum) {
            $range = $maximum[$index] - $componentMinimum;
            if ($range < 0 || ($range !== 0 && $maxCardinality > intdiv(PHP_INT_MAX, $range))) {
                throw new \OverflowException('A lexicographic component range is too large to encode.');
            }
            // Subtracting the per-edge minimum introduces a cardinality-
            // dependent offset when a non-perfect matching is allowed. The
            // leading cardinality field handles that globally; the component
            // fields must be sized for the complete shifted sum, including
            // maxCardinality * -minimum, or carries can corrupt the preceding
            // lexicographic criterion.
            $shiftedMaximum = $maximum[$index] - min(0, $componentMinimum);
            if ($shiftedMaximum !== 0 && $maxCardinality > intdiv(PHP_INT_MAX, $shiftedMaximum)) {
                throw new \OverflowException('A shifted lexicographic component is too large to encode.');
            }
            $fieldWidths[] = $this->bitsFor($maxCardinality * $shiftedMaximum);
        }

        usort($edges, static fn (WeightedEdge $left, WeightedEdge $right): int =>
            (min($left->left, $left->right) <=> min($right->left, $right->right))
            ?: (max($left->left, $left->right) <=> max($right->left, $right->right))
        );
        $tieFieldWidth = $this->includeMateVector ? $this->bitsFor(count($vertices)) : 0;
        $tieBits = $this->includeMateVector ? count($vertices) * $tieFieldWidth : 0;
        $totalBits = array_sum($fieldWidths) + $tieBits;
        // BBP matching::Computer reserves two high guard bits for doubled
        // internal edge weights and dual arithmetic. Keep at least 128 bits.
        $bitWidth = max(128, $totalBits + 2);
        $encoded = [];
        foreach ($edges as $edgeIndex => $edge) {
            $value = DynamicUInt::fromInt(1, $bitWidth);
            foreach ($edge->weight as $dimension => $component) {
                $value = $value
                    ->shiftLeft($fieldWidths[$dimension + 1])
                    ->addInt($component - $minimum[$dimension]);
            }
            $value = $value->shiftLeft($tieBits);
            // A matching contributes at most one partner digit per vertex, so
            // addition is carry-free. Earlier pairing numbers are more
            // significant; being matched dominates being unmatched and, for
            // a matched vertex, the lower partner number wins. The complete
            // mate vector uniquely identifies a matching using O(V log V)
            // bits instead of one perturbation bit per possible edge.
            if ($this->includeMateVector) {
                foreach ([$edge->left => $edge->right, $edge->right => $edge->left] as $vertex => $partner) {
                    $digit = count($vertices) - $partner;
                    $position = (count($vertices) - $vertex - 1) * $tieFieldWidth;
                    $value = $value->add(DynamicUInt::fromInt($digit, $bitWidth)->shiftLeft($position));
                }
            }
            $encoded[] = new EncodedWeightedEdge(
                min($edge->left, $edge->right),
                max($edge->left, $edge->right),
                $value,
            );
        }

        return new EncodedMatchingProblem($bitWidth, $encoded);
    }

    private function bitsFor(int $maximum): int
    {
        $bits = 1;
        while ($maximum > 1) {
            $maximum = intdiv($maximum, 2);
            ++$bits;
        }

        return $bits;
    }
}
