<?php

/*
 * Criterion update semantics derived from Gacrux/TieBreakServer
 * crosstabledutch.py, commit 14a34a2c2f36509b110e4f25d6247f31fc4bf2f5.
 * Copyright (c) 2024 FIDE. Gacrux is MIT licensed; see NOTICE.
 * Modified into an immutable PHP edge-quality model for OpenPairingPHP.
 */

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\ColourPreferenceStrength;
use OpenPairing\Engine\Dutch\DutchRules;

/**
 * Complete C6-C21 edge-quality model used by the persistent Dutch coordinator.
 *
 * Semantic port of the criterion ordering and edge updates in Gacrux
 * `crosstabledutch.py` at commit 14a34a2c, MIT; PHP data modelling is original.
 */
final readonly class DutchQualityModel
{
    public function __construct(
        private DutchRules $rules = new DutchRules(),
    ) {
    }

    /** @return list<int> PAB edge with the same flattened shape as player edges. */
    public function pabWeight(TournamentState $state, Player $player, int $width, int $qc9Offset): array
    {
        if ($qc9Offset < 0 || $qc9Offset >= $width) {
            throw new \InvalidArgumentException('QC9 offset is outside the PAB edge weight.');
        }
        $weight = array_fill(0, $width, 0);
        $weight[$qc9Offset] = -$this->unplayedGames($state, $player);

        return array_values($weight);
    }

    /**
     * @param array<int, int> $scoreRankByPlayerId Zero is the lowest occupied score group.
     * @param list<Player> $bracketPlayers Current MDPs plus residents and players needed for completion.
     * @param array<int, int> $bsnByPlayerId Bracket sequence numbers for HE/HO evaluation.
     */
    public function edge(
        TournamentState $state,
        Player $left,
        Player $right,
        int $scoreLevel,
        ?int $pabScoreLevel,
        array $scoreRankByPlayerId,
        array $bracketPlayers,
        array $bsnByPlayerId = [],
        int $homogeneousPairs = 0,
    ): DutchQualityVector {
        if ($left->pairingScore()->compare($right->pairingScore()) < 0) {
            [$left, $right] = [$right, $left];
        }
        $leftLevel = $scoreRankByPlayerId[$left->id];
        $rightLevel = $scoreRankByPlayerId[$right->id];
        $maxPsd = max(array_map(
            static fn (Player $player): int => $scoreRankByPlayerId[$player->id] - $scoreLevel,
            $bracketPlayers,
        ) ?: [0]);
        $c7 = array_fill(0, max(0, $maxPsd), 0);
        $c8 = array_fill(0, max(0, $maxPsd + 1), 0);
        $c18 = $c19 = $c20 = $c21 = array_fill(0, max(0, $maxPsd), 0);
        /** @var array<string, int|list<int>> $components */
        $components = [
            'QC6' => 0, 'QC7' => $c7, 'QN8' => 0, 'QC8' => $c8, 'QC9' => 0,
            'QC10' => 0, 'QC11' => 0, 'QC12' => 0, 'QC13' => 0,
            'QC14' => 0, 'QC15' => 0, 'QC16' => 0, 'QC17' => 0,
            'QC18' => $c18, 'QC19' => $c19, 'QC20' => $c20, 'QC21' => $c21,
            'HE1' => [], 'HE2' => [], 'HO1' => 0, 'HO2' => 0,
            'HO3' => [], 'HO4' => [], 'HO5' => [],
        ];

        if ($leftLevel >= $scoreLevel && $rightLevel === $scoreLevel) {
            [$c10, $c11, $c12, $c13] = $this->colourPenalties($state, $left, $right);
            $components['QC10'] = $c10;
            $components['QC11'] = $c11;
            $components['QC12'] = $c12;
            $components['QC13'] = $c13;
            if ($leftLevel > $rightLevel) {
                $components['QC15'] = $this->floatAt($right, 1, 'up');
                $components['QC17'] = $this->floatAt($right, 2, 'up');
            }
            if ($pabScoreLevel === $scoreLevel && $leftLevel === $scoreLevel) {
                // The PAB is the unmatched real vertex. BBP represents its
                // repeated-downfloat penalty by rewarding those endpoint
                // histories on same-group edges: maximizing the complement
                // keeps repeaters matched and leaves the best assignee free.
                $components['QC14'] = -$this->floatAt($left, 1, 'down') - $this->floatAt($right, 1, 'down');
                $components['QC16'] = -$this->floatAt($left, 2, 'down') - $this->floatAt($right, 2, 'down');
            }
        } elseif ($leftLevel >= $scoreLevel && $rightLevel < $scoreLevel) {
            $components['QC6'] = 1;
            $level = $leftLevel - $scoreLevel;
            if ($level > 0 && $maxPsd >= $level) {
                $qc7 = $components['QC7'];
                if (!is_array($qc7)) {
                    throw new \LogicException('QC7 must be vector-valued.');
                }
                $qc7[$maxPsd - $level] = 1;
                $components['QC7'] = array_values($qc7);
            }
            if ($leftLevel === $scoreLevel) {
                $components['QC14'] = $this->floatAt($left, 1, 'down');
                $components['QC16'] = $this->floatAt($left, 2, 'down');
            }
        }

        $nextLevel = $scoreLevel - 1;
        if ($leftLevel >= $nextLevel && $rightLevel < $nextLevel) {
            $components['QN8'] = 1;
            $level = max($leftLevel, $rightLevel) - $nextLevel;
            if ($level > 0 && $maxPsd + 1 >= $level) {
                $qc8 = $components['QC8'];
                if (!is_array($qc8)) {
                    throw new \LogicException('QC8 must be vector-valued.');
                }
                $qc8[$maxPsd + 1 - $level] = 1;
                $components['QC8'] = array_values($qc8);
            }
        }
        $floatLevel = max(0, $leftLevel - $rightLevel);
        foreach (['QC14' => 'QC18', 'QC15' => 'QC19', 'QC16' => 'QC20', 'QC17' => 'QC21'] as $counter => $position) {
            if ($components[$counter] === 1 && $floatLevel > 0 && $maxPsd >= $floatLevel) {
                $positionVector = $components[$position];
                if (!is_array($positionVector)) {
                    throw new \LogicException($position.' must be vector-valued.');
                }
                $positionVector[$maxPsd - $floatLevel] = 1;
                $components[$position] = array_values($positionVector);
            }
        }

        $components = $this->addOrderComponents($components, $left, $right, $scoreLevel, $scoreRankByPlayerId, $bsnByPlayerId, $homogeneousPairs);

        return new DutchQualityVector($components);
    }

    /**
     * @param array<string, int|list<int>> $components
     * @param array<int, int> $levels
     * @param array<int, int> $bsn
     * @return array<string, int|list<int>>
     */
    private function addOrderComponents(array $components, Player $left, Player $right, int $scoreLevel, array $levels, array $bsn, int $pairs): array
    {
        if ($bsn === []) {
            return $components;
        }
        $mdpCount = 0;
        foreach ($bsn as $playerId => $_sequenceNumber) {
            if ($levels[$playerId] > $scoreLevel) {
                ++$mdpCount;
            }
        }
        $components['HE1'] = array_fill(0, $mdpCount, 0);
        $components['HE2'] = array_fill(0, $mdpCount, 0);
        $components['HO1'] = 0;
        $components['HO2'] = 0;
        $components['HO3'] = array_fill(0, max(0, $pairs), 0);
        $components['HO4'] = array_fill(0, max(0, count($bsn) - $pairs), $pairs > 0 ? 1 : 0);
        $components['HO5'] = array_fill(0, max(0, count($bsn) - 1), 0);
        if (($levels[$left->id] > $scoreLevel) !== ($levels[$right->id] > $scoreLevel)) {
            $mdp = $levels[$left->id] > $scoreLevel ? $left : $right;
            $resident = $mdp === $left ? $right : $left;
            $index = ($bsn[$mdp->id] ?? 1) - 1;
            /** @var list<int> $he1 */
            $he1 = $components['HE1'];
            /** @var list<int> $he2 */
            $he2 = $components['HE2'];
            if (isset($he2[$index])) {
                $residentBsn = $bsn[$resident->id] ?? null;
                $he1[$index] = $residentBsn === null ? 1 : 0;
                $he2[$index] = $residentBsn === null ? 0 : max(0, $residentBsn - $mdpCount);
                $components['HE1'] = array_values($he1);
                $components['HE2'] = array_values($he2);
            }
            return $components;
        }
        if ($levels[$left->id] !== $scoreLevel || $levels[$right->id] !== $scoreLevel || $pairs < 1) {
            return $components;
        }
        $first = min($bsn[$left->id], $bsn[$right->id]);
        $second = max($bsn[$left->id], $bsn[$right->id]);
        $size = count($bsn);
        $components['HO1'] = $first > $pairs ? 1 : 0;
        $components['HO2'] = $first - 1;
        if ($first <= $pairs) {
            /** @var list<int> $ho3 */
            $ho3 = $components['HO3'];
            $ho3[$pairs - $first] = 1;
            $components['HO3'] = $ho3;
        } else {
            /** @var list<int> $ho4 */
            $ho4 = $components['HO4'];
            $ho4[$first - $pairs - 1] = 0;
            $components['HO4'] = $ho4;
        }
        /** @var list<int> $ho5 */
        $ho5 = $components['HO5'];
        $ho5[$first - 1] = $second - $first;
        $components['HO5'] = $ho5;

        /** @var array<string, int|list<int>> $components */
        return $components;
    }

    /** @return array{int, int, int, int} */
    private function colourPenalties(TournamentState $state, Player $left, Player $right): array
    {
        $leftPreference = $this->rules->colourPreference($left);
        $rightPreference = $this->rules->colourPreference($right);
        $samePreference = $leftPreference->colour !== null && $leftPreference->colour === $rightPreference->colour;
        $c12 = $samePreference ? 1 : 0;
        $c13 = $samePreference
            && $leftPreference->strength !== ColourPreferenceStrength::Mild
            && $rightPreference->strength !== ColourPreferenceStrength::Mild ? 1 : 0;
        $c10 = $c11 = 0;
        if ($this->rules->isTopscorer($state, $left) || $this->rules->isTopscorer($state, $right)) {
            if ($leftPreference->colour !== null
                && $leftPreference->colour === $rightPreference->colour
                && abs($leftPreference->difference) >= 2
                && abs($rightPreference->difference) >= 2) {
                $c10 = 1;
            }
            $leftHistory = array_values(array_filter($left->colourHistory, static fn (?Colour $value): bool => $value !== null));
            $rightHistory = array_values(array_filter($right->colourHistory, static fn (?Colour $value): bool => $value !== null));
            if ($samePreference && count($leftHistory) >= 2 && count($rightHistory) >= 2) {
                $leftLastTwo = array_slice($leftHistory, -2);
                $rightLastTwo = array_slice($rightHistory, -2);
                if ($leftLastTwo === $rightLastTwo && $leftLastTwo[0] === $leftLastTwo[1]) {
                    $c11 = 1;
                }
            }
        }

        return [$c10, $c11, $c12, $c13];
    }

    private function unplayedGames(TournamentState $state, Player $player): int
    {
        $played = 0;
        foreach ($player->results as $result) {
            if ($result['played']) {
                ++$played;
            }
        }

        return max(0, ($state->round - 1) - $played);
    }

    private function floatAt(Player $player, int $offset, string $direction): int
    {
        $index = count($player->floatHistory) - $offset;

        return $index >= 0 && ($player->floatHistory[$index] ?? null) === $direction ? 1 : 0;
    }
}
