<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Domain\Score\Score;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;

final readonly class DutchPreliminaryRoundResult
{
    /**
     * @param list<array{left: int, right: int}> $pairs
     * @param list<int> $unmatchedPlayerIds
     */
    public function __construct(
        public WeightedMatchingStatus $status,
        public array $pairs,
        public array $unmatchedPlayerIds,
        public ?Score $byeScore,
        public int $statesExplored,
    ) {
    }
}
