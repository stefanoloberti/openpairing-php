<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Tournament;

use InvalidArgumentException;

final class InvalidTournamentState extends InvalidArgumentException
{
    public function __construct(
        public readonly string $diagnosticCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

