<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

final readonly class EncodedMatchingProblem
{
    /** @param list<EncodedWeightedEdge> $edges */
    public function __construct(
        public int $bitWidth,
        public array $edges,
    ) {
    }
}
