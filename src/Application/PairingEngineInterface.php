<?php

declare(strict_types=1);

namespace OpenPairing\Application;

use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Tournament\TournamentState;

interface PairingEngineInterface
{
    public function pair(TournamentState $state): PairingResult;
}

