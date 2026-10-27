<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Tournament;

final readonly class ProhibitedPairingGroup
{
    /** @param list<int> $playerIds */
    public function __construct(
        public int $firstRound,
        public int $lastRound,
        public array $playerIds,
    ) {
    }

    public function prohibits(int $round, int $leftPlayerId, int $rightPlayerId): bool
    {
        return $round >= $this->firstRound
            && $round <= $this->lastRound
            && in_array($leftPlayerId, $this->playerIds, true)
            && in_array($rightPlayerId, $this->playerIds, true);
    }

    /** @return array{firstRound: int, lastRound: int, playerIds: list<int>} */
    public function toArray(): array
    {
        return [
            'firstRound' => $this->firstRound,
            'lastRound' => $this->lastRound,
            'playerIds' => $this->playerIds,
        ];
    }
}
