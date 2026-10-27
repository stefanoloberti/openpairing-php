<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;

final class DutchRules
{
    public function colourPreference(Player $player): ColourPreference
    {
        $playedColours = $this->playedColours($player);
        $difference = 0;
        foreach ($playedColours as $colour) {
            $difference += match ($colour) {
                Colour::White => 1,
                Colour::Black => -1,
            };
        }

        if ($playedColours === []) {
            return new ColourPreference(null, ColourPreferenceStrength::None, 0);
        }

        $last = $playedColours[array_key_last($playedColours)];
        $lastTwoEqual = count($playedColours) >= 2
            && $playedColours[count($playedColours) - 2] === $last;
        if ($difference < -1) {
            return new ColourPreference(Colour::White, ColourPreferenceStrength::Absolute, $difference);
        }
        if ($difference > 1) {
            return new ColourPreference(Colour::Black, ColourPreferenceStrength::Absolute, $difference);
        }
        if ($lastTwoEqual) {
            return new ColourPreference($last->opposite(), ColourPreferenceStrength::Absolute, $difference);
        }
        if ($difference === -1) {
            return new ColourPreference(Colour::White, ColourPreferenceStrength::Strong, $difference);
        }
        if ($difference === 1) {
            return new ColourPreference(Colour::Black, ColourPreferenceStrength::Strong, $difference);
        }

        return new ColourPreference($last->opposite(), ColourPreferenceStrength::Mild, $difference);
    }

    public function isTopscorer(TournamentState $state, Player $player): bool
    {
        if ($state->round !== $state->numberOfRounds) {
            return false;
        }

        $maximum = Score::zero();
        for ($round = 1; $round < $state->round; ++$round) {
            $maximum = $maximum->add($state->pointsForWin);
        }

        return ($player->score->units * $maximum->scale * 2) > ($maximum->units * $player->score->scale);
    }

    public function canMeet(TournamentState $state, Player $left, Player $right): bool
    {
        if ($left->id === $right->id
            || in_array($right->id, $left->opponentHistory, true)
            || $state->isPairingProhibited($left->id, $right->id)) {
            return false;
        }

        $leftPreference = $this->colourPreference($left);
        $rightPreference = $this->colourPreference($right);
        if (!$this->isTopscorer($state, $left)
            && !$this->isTopscorer($state, $right)
            && $leftPreference->strength === ColourPreferenceStrength::Absolute
            && $rightPreference->strength === ColourPreferenceStrength::Absolute
            && $leftPreference->colour === $rightPreference->colour) {
            return false;
        }

        return true;
    }

    public function isEligibleForPairingAllocatedBye(TournamentState $state, Player $player): bool
    {
        if ($player->receivedPairingAllocatedBye) {
            return false;
        }

        foreach ($player->results as $result) {
            if (!$result['played'] && $this->pointsForResult($state, strtoupper($result['result']))->compare($state->pointsForWin) === 0) {
                return false;
            }
        }

        return true;
    }

    public function pointsForResult(TournamentState $state, string $code): Score
    {
        return match (strtoupper($code)) {
            '1', 'W', '+', 'F' => $state->pointsForWin,
            '=', 'D', 'H' => $state->pointsForDraw,
            '0', 'L' => $state->lossScore(),
            '-', 'Z' => $state->zeroPointByeScore(),
            'U' => $state->pairingAllocatedByeScore(),
            default => $state->unknownScore(),
        };
    }

    /** @return list<Colour> */
    private function playedColours(Player $player): array
    {
        $colours = [];
        foreach ($player->colourHistory as $colour) {
            if ($colour instanceof Colour) {
                $colours[] = $colour;
            }
        }

        return $colours;
    }
}
