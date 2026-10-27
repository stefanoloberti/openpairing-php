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
     * @param list<Score|null> $accelerationSchedule Round-indexed virtual points; index zero is round one.
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
        public array $accelerationSchedule = [],
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

    public function accelerationForRound(int $round): ?Score
    {
        if ($round < 1) {
            return null;
        }

        return $this->accelerationSchedule[$round - 1] ?? null;
    }

    public function withAccelerationForRound(int $round): self
    {
        $acceleration = $this->accelerationSchedule === []
            ? $this->acceleration
            : $this->accelerationForRound($round);

        return new self(
            id: $this->id,
            pairingNumber: $this->pairingNumber,
            name: $this->name,
            rating: $this->rating,
            title: $this->title,
            team: $this->team,
            club: $this->club,
            active: $this->active,
            score: $this->score,
            opponentHistory: $this->opponentHistory,
            colourHistory: $this->colourHistory,
            results: $this->results,
            receivedPairingAllocatedBye: $this->receivedPairingAllocatedBye,
            floatHistory: $this->floatHistory,
            acceleration: $acceleration,
            sex: $this->sex,
            federation: $this->federation,
            fideId: $this->fideId,
            birthDate: $this->birthDate,
            rank: $this->rank,
            accelerationSchedule: $this->accelerationSchedule,
        );
    }
}
