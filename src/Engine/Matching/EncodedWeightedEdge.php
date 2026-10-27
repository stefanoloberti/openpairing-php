<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

final readonly class EncodedWeightedEdge
{
    public function __construct(
        public int $left,
        public int $right,
        public DynamicUInt $weight,
    ) {
    }
}
