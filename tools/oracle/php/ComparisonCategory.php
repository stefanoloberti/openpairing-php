<?php

declare(strict_types=1);

namespace OpenPairing\Development\Oracle;

enum ComparisonCategory: string
{
    case Exact = 'exact';
    case SamePairsColourVariant = 'same-pairs-colour-variant';
    case LegitimateVariant = 'legitimate-variant';
    case OracleDeviation = 'oracle-deviation';
    case OurViolation = 'our-violation';
    case UpstreamInvalid = 'upstream-invalid';
    case BothInvalid = 'both-invalid';
    case InputUnsupported = 'input-unsupported';
    case InfrastructureError = 'infrastructure-error';
    case ResourceLimit = 'resource-limit';
}
