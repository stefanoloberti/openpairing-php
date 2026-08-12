<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;

final readonly class ColourAllocator
{
    public function __construct(private DutchRules $rules = new DutchRules())
    {
    }

    public function allocate(TournamentState $state, Player $left, Player $right): ColourAllocation
    {
        $leftPreference = $this->rules->colourPreference($left);
        $rightPreference = $this->rules->colourPreference($right);

        if ($leftPreference->colour !== null
            && $rightPreference->colour !== null
            && $leftPreference->colour !== $rightPreference->colour) {
            return $this->grant($left, $right, $leftPreference->colour, '5.2.1');
        }

        $leftStrength = $this->strength($leftPreference->strength);
        $rightStrength = $this->strength($rightPreference->strength);
        if ($leftPreference->colour !== null && $leftStrength > $rightStrength) {
            return $this->grant($left, $right, $leftPreference->colour, '5.2.2');
        }
        if ($rightPreference->colour !== null && $rightStrength > $leftStrength) {
            return $this->grant($right, $left, $rightPreference->colour, '5.2.2');
        }
        if ($leftPreference->strength === ColourPreferenceStrength::Absolute
            && $rightPreference->strength === ColourPreferenceStrength::Absolute
            && abs($leftPreference->difference) !== abs($rightPreference->difference)) {
            $preferred = abs($leftPreference->difference) > abs($rightPreference->difference) ? $left : $right;
            $other = $preferred === $left ? $right : $left;
            $preference = $preferred === $left ? $leftPreference : $rightPreference;

            return $this->grant($preferred, $other, $preference->colour ?? Colour::White, '5.2.2');
        }

        $leftHistory = $this->playedColours($left);
        $rightHistory = $this->playedColours($right);
        for ($offset = 1; $offset <= min(count($leftHistory), count($rightHistory)); ++$offset) {
            $leftPrevious = $leftHistory[count($leftHistory) - $offset];
            $rightPrevious = $rightHistory[count($rightHistory) - $offset];
            if ($leftPrevious !== $rightPrevious) {
                return $this->grant($left, $right, $leftPrevious->opposite(), '5.2.3');
            }
        }

        $higher = $this->higherRanked($left, $right);
        $lower = $higher === $left ? $right : $left;
        $higherPreference = $higher === $left ? $leftPreference : $rightPreference;
        if ($higherPreference->colour !== null) {
            return $this->grant($higher, $lower, $higherPreference->colour, '5.2.4');
        }

        $higherColour = $higher->pairingNumber % 2 === 1 ? $state->topColour : $state->topColour->opposite();

        return $this->grant($higher, $lower, $higherColour, '5.2.5');
    }

    private function grant(Player $preferred, Player $other, Colour $colour, string $rule): ColourAllocation
    {
        return $colour === Colour::White
            ? new ColourAllocation($preferred, $other, $rule)
            : new ColourAllocation($other, $preferred, $rule);
    }

    private function higherRanked(Player $left, Player $right): Player
    {
        $scoreComparison = $left->pairingScore()->compare($right->pairingScore());
        if ($scoreComparison !== 0) {
            return $scoreComparison > 0 ? $left : $right;
        }

        return $left->pairingNumber < $right->pairingNumber ? $left : $right;
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
