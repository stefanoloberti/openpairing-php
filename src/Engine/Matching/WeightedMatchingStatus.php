<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

enum WeightedMatchingStatus: string
{
    case Optimal = 'optimal';
    case NoMatching = 'no_matching';
}
