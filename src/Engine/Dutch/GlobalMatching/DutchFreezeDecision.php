<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Engine\Matching\WeightedEdge;

final readonly class DutchFreezeDecision
{
    /** @param list<int> $wholeRoundWeight */
    public function __construct(
        public WeightedEdge $edge,
        public array $wholeRoundWeight,
        public int $statesExplored,
    ) {
    }
}
