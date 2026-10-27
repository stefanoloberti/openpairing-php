<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Burstein;

use OpenPairing\Domain\Tournament\Player;

final readonly class BursteinSolution
{
    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @param list<BursteinCandidate> $brackets
     * @param list<array{step: int, code: string, detail: string}> $trace
     */
    public function __construct(
        public array $pairs,
        public array $brackets,
        public array $trace,
    ) {
    }
}
