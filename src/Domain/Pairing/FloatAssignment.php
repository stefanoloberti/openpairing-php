<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Pairing;

final readonly class FloatAssignment
{
    public function __construct(
        public int $playerId,
        public FloatDirection $direction,
    ) {
        if ($playerId < 1) {
            throw new \InvalidArgumentException('A float assignment requires a positive player ID.');
        }
    }

    /** @return array{playerId: int, direction: 'up'|'down'} */
    public function toArray(): array
    {
        return ['playerId' => $this->playerId, 'direction' => $this->direction->value];
    }
}
