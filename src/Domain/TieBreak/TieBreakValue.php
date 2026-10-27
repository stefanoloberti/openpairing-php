<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

use OpenPairing\Domain\Score\Score;

final readonly class TieBreakValue
{
    public function __construct(
        public Score $buchholz,
        public Score $sonnebornBerger,
    ) {
    }

    /** @return array{buchholz: array{units: int, scale: int}, sonnebornBerger: array{units: int, scale: int}} */
    public function toArray(): array
    {
        return [
            'buchholz' => $this->buchholz->toArray(),
            'sonnebornBerger' => $this->sonnebornBerger->toArray(),
        ];
    }
}
