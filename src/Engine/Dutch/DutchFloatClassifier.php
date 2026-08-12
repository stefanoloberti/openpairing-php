<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\TournamentState;

/** Canonical Dutch float classification for both live and imported results. */
final readonly class DutchFloatClassifier
{
    public const VERSION = 'fide-dutch-float/2-pab-downfloat';

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
        if ($played && $opponentEnteringScore !== null) {
            $comparison = $ownEnteringScore->compare($opponentEnteringScore);

            return $comparison > 0 ? 'down' : ($comparison < 0 ? 'up' : 'none');
        }

        // FIDE Dutch/Gacrux: a scoring allocation is a downfloat when it
        // awards more than the configured loss score. This includes a PAB,
        // a half-point bye and an unplayed forfeit win. An optional opponent
        // recorded for a forfeit does not turn it into a played pairing.
        if ($played || $this->rules->pointsForResult($state, $resultCode)->compare($state->lossScore()) > 0) {
            return 'down';
        }

        return 'none';
    }
}
