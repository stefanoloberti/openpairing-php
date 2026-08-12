<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Engine\Matching\WeightedMatchingResult;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;

/** Updates BBP's conditional C9 state after each completed bracket. */
final readonly class DutchC9Lifecycle
{
    /** @param list<int> $remainingPlayerIds */
    public function appliesInNextBracket(
        ?int $pabScoreLevel,
        int $nextScoreLevel,
        array $remainingPlayerIds,
        WeightedMatchingResult $stableMatching,
        DutchRoundGraph $graph,
    ): bool {
        if ($pabScoreLevel === null
            || $pabScoreLevel < $nextScoreLevel
            || $stableMatching->status !== WeightedMatchingStatus::Optimal) {
            return false;
        }

        $partnerByPlayerId = [];
        foreach ($stableMatching->pairs as $pair) {
            $partnerByPlayerId[$pair['left']] = $pair['right'];
            $partnerByPlayerId[$pair['right']] = $pair['left'];
        }
        foreach ($remainingPlayerIds as $playerId) {
            if ($graph->scoreRankByPlayerId[$playerId] < $nextScoreLevel) {
                continue;
            }
            $opponentId = $partnerByPlayerId[$playerId] ?? null;
            if ($opponentId !== null && $graph->scoreRankByPlayerId[$opponentId] < $nextScoreLevel) {
                return false;
            }
        }

        return true;
    }
}
