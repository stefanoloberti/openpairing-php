<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Tournament;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;

final readonly class Player
{
    /**
     * @param list<int> $opponentHistory
     * @param list<Colour|null> $colourHistory
     * @param list<array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool}> $results
     * @param list<string> $floatHistory
     */
    public function __construct(
        public int $id,
        public int $pairingNumber,
        public string $name,
        public int $rating,
        public string $title,
        public string $team,
        public string $club,
        public bool $active,
        public Score $score,
        public array $opponentHistory = [],
        public array $colourHistory = [],
        public array $results = [],
        public bool $receivedPairingAllocatedBye = false,
        public array $floatHistory = [],
        public ?Score $acceleration = null,
        public string $sex = '',
        public string $federation = '',
        public int $fideId = 0,
        public string $birthDate = '',
        public int $rank = 0,
    ) {
    }

    public function hasPlayedRound(): bool
    {
        return $this->results !== [] || $this->opponentHistory !== [] || $this->colourHistory !== [];
    }

    /**
     * FIDE C.04.7 Article 1.5: standings points plus the virtual points
     * assigned by the announced acceleration method.
     */
    public function pairingScore(): Score
    {
        return $this->acceleration === null ? $this->score : $this->score->add($this->acceleration);
    }
}
