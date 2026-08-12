<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Colour\Colour;

final readonly class ColourPreference
{
    public function __construct(
        public ?Colour $colour,
        public ColourPreferenceStrength $strength,
        public int $difference,
    ) {
    }
}
