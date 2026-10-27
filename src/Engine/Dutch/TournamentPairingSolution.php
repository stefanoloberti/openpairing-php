<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Tournament\Player;

final readonly class TournamentPairingSolution
{
    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @param list<int> $quality
     * @param list<array{step: int, code: string, detail: string}> $trace
     */
    public function __construct(
        public array $pairs,
        public array $quality,
        public array $trace,
    ) {
    }
}
