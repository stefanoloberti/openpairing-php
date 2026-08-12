<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\TournamentState;

/** Reconstructs Dutch float history from historical TRF round facts. */
final readonly class HistoricalFloatClassifier
{
    public function __construct(private DutchRules $rules = new DutchRules())
    {
    }

    public function direction(
        TournamentState $state,
        Score $ownEnteringScore,
        ?Score $opponentEnteringScore,
        string $resultCode,
        bool $played,
    ): string {
        if ($opponentEnteringScore !== null) {
            $comparison = $ownEnteringScore->compare($opponentEnteringScore);

            return $comparison > 0 ? 'down' : ($comparison < 0 ? 'up' : 'none');
        }
        // Gacrux compute_flt_dutch and FIDE Dutch treat a non-played scoring
        // allocation (notably a PAB) as a downfloat when it awards more than
        // the configured loss score.
        if ($played || $this->rules->pointsForResult($state, $resultCode)->compare($state->lossScore()) > 0) {
            return 'down';
        }

        return 'none';
    }
}
