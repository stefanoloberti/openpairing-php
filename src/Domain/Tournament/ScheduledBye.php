<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Tournament;

final readonly class ScheduledBye
{
    /** @param list<int> $playerIds */
    public function __construct(
        public string $type,
        public int $round,
        public array $playerIds,
    ) {
    }

    public function includes(int $round, int $playerId): bool
    {
        return $this->round === $round && in_array($playerId, $this->playerIds, true);
    }

    /** @return array{type: string, round: int, playerIds: list<int>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'round' => $this->round,
            'playerIds' => $this->playerIds,
        ];
    }
}
