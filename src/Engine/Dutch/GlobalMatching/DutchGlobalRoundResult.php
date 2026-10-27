<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Engine\Matching\WeightedMatchingStatus;

final readonly class DutchGlobalRoundResult
{
    /**
     * @param list<array{left: int, right: int}> $pairs
     * @param list<int> $unmatchedPlayerIds
     * @param list<DutchBracketTiming> $timings
     * @param list<DutchFreezeEvent> $freezeEvents
     */
    public function __construct(
        public WeightedMatchingStatus $status,
        public array $pairs,
        public array $unmatchedPlayerIds,
        public ?int $byePlayerId,
        public array $timings,
        public array $freezeEvents,
    ) {
    }
}
