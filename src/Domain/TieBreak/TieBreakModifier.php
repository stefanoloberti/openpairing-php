<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

enum TieBreakModifier: string
{
    case None = 'none';
    case Cut1 = 'cut-1';
    case Cut2 = 'cut-2';
    case Median1 = 'median-1';
    case Median2 = 'median-2';
}
