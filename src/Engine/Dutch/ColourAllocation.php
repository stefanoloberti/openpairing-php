<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Tournament\Player;

final readonly class ColourAllocation
{
    public function __construct(
        public Player $white,
        public Player $black,
        public string $rule,
    ) {
    }
}
