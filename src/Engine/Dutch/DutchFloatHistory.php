<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;

/** Resolves Dutch float recency by tournament round, including participation gaps. */
final readonly class DutchFloatHistory
{
    public function at(TournamentState $state, Player $player, int $offset, string $direction): int
    {
        // Low-level fixtures may supply only the compact vector. Validated
        // states also carry result rounds, which are authoritative.
        if ($player->results === []) {
            $index = count($player->floatHistory) - $offset;

            return $index >= 0 && ($player->floatHistory[$index] ?? null) === $direction ? 1 : 0;
        }

        $targetRound = $state->round - $offset;
        foreach ($player->results as $index => $result) {
            if ($result['round'] === $targetRound) {
                return ($player->floatHistory[$index] ?? null) === $direction ? 1 : 0;
            }
        }

        return 0;
    }
}
