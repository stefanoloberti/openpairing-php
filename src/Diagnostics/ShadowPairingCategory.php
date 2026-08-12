<?php

declare(strict_types=1);

namespace OpenPairing\Diagnostics;

enum ShadowPairingCategory: string
{
    case BothExact = 'both-exact';
    case CandidateProgress = 'candidate-progress';
    case CandidateRegression = 'candidate-regression';
    case SameDeviation = 'same-deviation';
    case DivergentDeviation = 'divergent-deviation';
}
