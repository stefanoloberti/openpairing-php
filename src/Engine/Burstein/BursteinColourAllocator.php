<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Burstein;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\ColourAllocation;
use OpenPairing\Engine\Dutch\ColourPreferenceStrength;

final readonly class BursteinColourAllocator
{
    public function __construct(private BursteinRules $rules = new BursteinRules())
    {
    }

    public function allocate(
        TournamentState $state,
        Player $left,
        Player $right,
        BursteinRanker $ranker,
    ): ColourAllocation {
        $leftPreference = $this->rules->colourPreference($left);
        $rightPreference = $this->rules->colourPreference($right);

        if (!$left->hasPlayedRound() && !$right->hasPlayedRound()) {
            $higher = $ranker->isHigherRanked($left, $right) ? $left : $right;
            $lower = $higher === $left ? $right : $left;
            $colour = $higher->pairingNumber % 2 === 1 ? $state->topColour : $state->topColour->opposite();

            return $this->grant($higher, $lower, $colour, '5.2.1');
        }

        if ($leftPreference->colour !== null
            && $rightPreference->colour !== null
            && $leftPreference->colour !== $rightPreference->colour) {
            return $this->grant($left, $right, $leftPreference->colour, '5.2.2');
        }

        $leftStrength = $this->strength($leftPreference->strength);
        $rightStrength = $this->strength($rightPreference->strength);
        if ($leftPreference->colour !== null && $leftStrength > $rightStrength) {
            return $this->grant($left, $right, $leftPreference->colour, '5.2.3');
        }
        if ($rightPreference->colour !== null && $rightStrength > $leftStrength) {
            return $this->grant($right, $left, $rightPreference->colour, '5.2.3');
        }
        if ($leftPreference->strength === ColourPreferenceStrength::Absolute
            && $rightPreference->strength === ColourPreferenceStrength::Absolute
            && abs($leftPreference->difference) !== abs($rightPreference->difference)) {
            $preferred = abs($leftPreference->difference) > abs($rightPreference->difference) ? $left : $right;
            $other = $preferred === $left ? $right : $left;
            $preference = $preferred === $left ? $leftPreference : $rightPreference;

            return $this->grant($preferred, $other, $preference->colour ?? Colour::White, '5.2.3');
        }

        $leftHistory = $this->playedColours($left);
        $rightHistory = $this->playedColours($right);
        for ($offset = 1; $offset <= min(count($leftHistory), count($rightHistory)); ++$offset) {
            $leftPrevious = $leftHistory[count($leftHistory) - $offset];
            $rightPrevious = $rightHistory[count($rightHistory) - $offset];
            if ($leftPrevious !== $rightPrevious) {
                return $this->grant($left, $right, $leftPrevious->opposite(), '5.2.4');
            }
        }

        $higher = $ranker->isHigherRanked($left, $right) ? $left : $right;
        $lower = $higher === $left ? $right : $left;
        $preference = $higher === $left ? $leftPreference : $rightPreference;
        if ($preference->colour !== null) {
            return $this->grant($higher, $lower, $preference->colour, '5.2.5');
        }

        $colour = $higher->pairingNumber % 2 === 1 ? $state->topColour : $state->topColour->opposite();

        return $this->grant($higher, $lower, $colour, '5.2.5');
    }

    private function grant(Player $preferred, Player $other, Colour $colour, string $rule): ColourAllocation
    {
        return $colour === Colour::White
            ? new ColourAllocation($preferred, $other, $rule)
            : new ColourAllocation($other, $preferred, $rule);
    }

    private function strength(ColourPreferenceStrength $strength): int
    {
        return match ($strength) {
            ColourPreferenceStrength::None => 0,
            ColourPreferenceStrength::Mild => 1,
            ColourPreferenceStrength::Strong => 2,
            ColourPreferenceStrength::Absolute => 3,
        };
    }

    /** @return list<Colour> */
    private function playedColours(Player $player): array
    {
        return array_values(array_filter(
            $player->colourHistory,
            static fn (?Colour $colour): bool => $colour !== null,
        ));
    }
}
