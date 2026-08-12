<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

enum ColourPreferenceStrength: string
{
    case None = 'none';
    case Mild = 'mild';
    case Strong = 'strong';
    case Absolute = 'absolute';
}
