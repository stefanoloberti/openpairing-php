<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

final readonly class DutchBracketTiming
{
    public function __construct(
        public int $scoreLevel,
        public string $phase,
        public float $milliseconds,
        public int $matchingOperations,
        public int $remainingVertices,
    ) {
    }
}
