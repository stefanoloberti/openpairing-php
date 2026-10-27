<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;

final readonly class BracketCandidate
{
    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @param list<Player> $downfloaters
     * @param list<int> $quality
     * @param array{c18: list<Score>, c19: list<Score>, c20: list<Score>, c21: list<Score>} $floatScoreDifferences
     * @param list<int> $homogeneousOrderQuality HO1-HO5 expanded lexicographically.
     * @param list<int> $heterogeneousOrderQuality HE1-HE2 expanded lexicographically.
     */
    public function __construct(
        public array $pairs,
        public array $downfloaters,
        public array $quality,
        public array $floatScoreDifferences,
        public array $heterogeneousOrderQuality,
        public array $homogeneousOrderQuality,
        public int $generation,
    ) {
    }
}
