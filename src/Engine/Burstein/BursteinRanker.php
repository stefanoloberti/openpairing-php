<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Burstein;

use OpenPairing\Domain\TieBreak\BursteinOppositionEvaluation;
use OpenPairing\Domain\TieBreak\TieBreakValue;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;

final class BursteinRanker
{
    /** @var array<int, TieBreakValue> */
    private array $values;

    public function __construct(TournamentState $state)
    {
        $this->values = (new BursteinOppositionEvaluation())->calculate($state);
    }

    public function compare(Player $left, Player $right): int
    {
        $leftValue = $this->values[$left->id];
        $rightValue = $this->values[$right->id];
        $buchholz = $rightValue->buchholz->compare($leftValue->buchholz);
        if ($buchholz !== 0) {
            return $buchholz;
        }
        $sonnebornBerger = $rightValue->sonnebornBerger->compare($leftValue->sonnebornBerger);

        return $sonnebornBerger !== 0
            ? $sonnebornBerger
            : $left->pairingNumber <=> $right->pairingNumber;
    }

    /** @param list<Player> $players */
    public function sort(array &$players): void
    {
        usort($players, $this->compare(...));
    }

    public function isHigherRanked(Player $left, Player $right): bool
    {
        return $this->compare($left, $right) < 0;
    }

    public function value(Player $player): TieBreakValue
    {
        return $this->values[$player->id];
    }
}
