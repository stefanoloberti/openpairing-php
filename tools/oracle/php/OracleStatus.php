<?php

declare(strict_types=1);

namespace OpenPairing\Development\Oracle;

enum OracleStatus: string
{
    case Valid = 'valid';
    case NoPairing = 'no_pairing';
    case UnsupportedInput = 'unsupported_input';
    case OracleError = 'oracle_error';
    case Timeout = 'timeout';
    case InfrastructureError = 'infrastructure_error';
    case InvalidInput = 'invalid_input';
    case ResourceLimit = 'resource_limit';
}
