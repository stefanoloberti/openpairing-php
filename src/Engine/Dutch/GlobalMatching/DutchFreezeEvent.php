<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

/** One irreversible decision made by the persistent bracket coordinator. */
final readonly class DutchFreezeEvent
{
    public function __construct(
        public int $sequence,
        public int $bracketIndex,
        public int $scoreLevel,
        public int $left,
        public int $right,
        public int $remainingVerticesBefore,
        public int $remainingVerticesAfter,
    ) {
    }
}
