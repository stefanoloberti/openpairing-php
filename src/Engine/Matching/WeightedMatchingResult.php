<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

final readonly class WeightedMatchingResult
{
    /**
     * @param list<array{left: int, right: int}> $pairs
     * @param list<int> $unmatched
     * @param list<int> $totalWeight
     */
    public function __construct(
        public WeightedMatchingStatus $status,
        public array $pairs,
        public array $unmatched,
        public array $totalWeight,
        public int $cardinality,
        public int $statesExplored,
    ) {
    }
}
