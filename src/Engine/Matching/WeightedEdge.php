<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

final readonly class WeightedEdge
{
    /** @param list<int> $weight */
    public function __construct(
        public int $left,
        public int $right,
        public array $weight,
    ) {
    }
}
