<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchRules;

/** Builds one compatibility graph over every active player in the round. */
final readonly class DutchRoundGraphBuilder
{
    public function __construct(private DutchRules $rules = new DutchRules())
    {
    }

    public function build(TournamentState $state): DutchRoundGraph
    {
        $players = $state->activePlayers();
        usort($players, static function (Player $left, Player $right): int {
            $score = $right->pairingScore()->compare($left->pairingScore());

            return $score !== 0 ? $score : $left->pairingNumber <=> $right->pairingNumber;
        });
        $vertices = [];
        $playersById = [];
        foreach ($players as $player) {
            $vertices[] = $player->id;
            $playersById[$player->id] = $player;
        }

        $compatibleEdges = [];
        foreach ($players as $leftIndex => $left) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < count($players); ++$rightIndex) {
                $right = $players[$rightIndex];
                if ($this->rules->canMeet($state, $left, $right)) {
                    $compatibleEdges[] = [
                        'left' => min($left->id, $right->id),
                        'right' => max($left->id, $right->id),
                    ];
                }
            }
        }

        $ascending = $players;
        usort($ascending, static function (Player $left, Player $right): int {
            $score = $left->pairingScore()->compare($right->pairingScore());

            return $score !== 0 ? $score : $left->pairingNumber <=> $right->pairingNumber;
        });
        $scoreRankByPlayerId = [];
        $rank = -1;
        $previous = null;
        foreach ($ascending as $player) {
            if ($previous === null || $previous->pairingScore()->compare($player->pairingScore()) !== 0) {
                ++$rank;
                $previous = $player;
            }
            $scoreRankByPlayerId[$player->id] = $rank;
        }

        return new DutchRoundGraph($vertices, $playersById, $compatibleEdges, $scoreRankByPlayerId);
    }
}
