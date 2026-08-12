<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Burstein;

use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;

final readonly class BursteinCandidate
{
    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @param list<Player> $downfloaters
     * @param list<Score> $downfloaterScores
     * @param list<int> $opponentVector
     */
    public function __construct(
        public array $pairs,
        public array $downfloaters,
        public array $downfloaterScores,
        public int $unmetColourPreferences,
        public array $opponentVector,
        public int $generation,
    ) {
    }
}
