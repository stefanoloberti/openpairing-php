<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

interface WeightedGeneralMatchingInterface
{
    /**
     * Maximises cardinality first, then the summed integer weight vector
     * lexicographically. Vertex and edge input order must not affect the result.
     *
     * @param list<int> $vertices
     * @param list<WeightedEdge> $edges
     */
    public function match(array $vertices, array $edges, bool $requirePerfect = false): WeightedMatchingResult;
}
