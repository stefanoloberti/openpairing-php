<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Burstein;

use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\ColourPreference;
use OpenPairing\Engine\Dutch\ColourPreferenceStrength;
use OpenPairing\Engine\Dutch\DutchRules;

final readonly class BursteinRules
{
    public function __construct(private DutchRules $commonRules = new DutchRules())
    {
    }

    public function colourPreference(Player $player): ColourPreference
    {
        return $this->commonRules->colourPreference($player);
    }

    public function canMeet(Player $left, Player $right): bool
    {
        if ($left->id === $right->id || in_array($right->id, $left->opponentHistory, true)) {
            return false;
        }

        $leftPreference = $this->colourPreference($left);
        $rightPreference = $this->colourPreference($right);

        return $leftPreference->strength !== ColourPreferenceStrength::Absolute
            || $rightPreference->strength !== ColourPreferenceStrength::Absolute
            || $leftPreference->colour !== $rightPreference->colour;
    }

    public function isEligibleForPairingAllocatedBye(TournamentState $state, Player $player): bool
    {
        return $this->commonRules->isEligibleForPairingAllocatedBye($state, $player);
    }
}
