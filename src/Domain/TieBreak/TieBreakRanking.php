<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\TournamentState;

final readonly class TieBreakRanking
{
    /**
     * @param list<list<int>> $groups ordered best first; each inner list remains tied
     * @param array<string, array<int, Score>> $evaluations
     */
    public function __construct(
        public array $groups,
        public array $evaluations,
    ) {
    }

    /** @return array{groups: list<list<int>>, evaluations: array<string, array<int, array{units: int, scale: int}>>} */
    public function toArray(): array
    {
        $evaluations = [];
        foreach ($this->evaluations as $identifier => $values) {
            foreach ($values as $playerId => $value) {
                $evaluations[$identifier][$playerId] = $value->toArray();
            }
        }

        return ['groups' => $this->groups, 'evaluations' => $evaluations];
    }

    /**
     * @return list<array{
     *   rank: int, playerId: int, pairingNumber: int, name: string,
     *   score: array{units: int, scale: int}, tieBreaks: array<string, array{units: int, scale: int}>
     * }>
     */
    public function standings(TournamentState $state): array
    {
        $players = [];
        foreach ($state->players as $player) {
            $players[$player->id] = $player;
        }
        $rows = [];
        $rank = 1;
        foreach ($this->groups as $group) {
            foreach ($group as $playerId) {
                $tieBreaks = [];
                foreach ($this->evaluations as $identifier => $values) {
                    $tieBreaks[$identifier] = $values[$playerId]->toArray();
                }
                $rows[] = [
                    'rank' => $rank,
                    'playerId' => $playerId,
                    'pairingNumber' => $players[$playerId]->pairingNumber,
                    'name' => $players[$playerId]->name,
                    'score' => $players[$playerId]->score->toArray(),
                    'tieBreaks' => $tieBreaks,
                ];
            }
            $rank += count($group);
        }

        return $rows;
    }
}
