<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Pairing;

final readonly class PairingTable
{
    public function __construct(
        public int $board,
        public int $whitePlayerId,
        public int $blackPlayerId,
    ) {
    }

    /** @return array{board: int, whitePlayerId: int, blackPlayerId: int} */
    public function toArray(): array
    {
        return [
            'board' => $this->board,
            'whitePlayerId' => $this->whitePlayerId,
            'blackPlayerId' => $this->blackPlayerId,
        ];
    }
}

