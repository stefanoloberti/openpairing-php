<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

final readonly class MatchingSolution
{
    /**
     * @param list<array{left: int, right: int}> $pairs
     * @param list<int> $unmatched
     * @param list<int> $weight
     */
    public function __construct(
        public array $pairs,
        public array $unmatched,
        public array $weight,
    ) {
    }

    public function cardinality(): int
    {
        return count($this->pairs);
    }
}
