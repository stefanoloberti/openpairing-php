<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

use OpenPairing\Domain\Score\Score;

final readonly class TieBreakElement
{
    public function __construct(
        public Score $value,
        public Score $opponentScore,
        public bool $voluntaryUnplayedRound,
        public int $round,
    ) {
    }
}
