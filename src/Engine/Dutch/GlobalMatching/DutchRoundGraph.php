<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Domain\Tournament\Player;

final readonly class DutchRoundGraph
{
    /**
     * @param list<int> $vertices
     * @param array<int, Player> $playersById
     * @param list<array{left: int, right: int}> $compatibleEdges
     * @param array<int, int> $scoreRankByPlayerId Zero is the lowest occupied score group.
     */
    public function __construct(
        public array $vertices,
        public array $playersById,
        public array $compatibleEdges,
        public array $scoreRankByPlayerId,
    ) {
    }
}
