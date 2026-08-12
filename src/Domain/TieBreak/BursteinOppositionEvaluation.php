<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchRules;

final readonly class BursteinOppositionEvaluation
{
    public function __construct(private DutchRules $rules = new DutchRules())
    {
    }

    /** @return array<int, TieBreakValue> keyed by canonical player ID */
    public function calculate(TournamentState $state): array
    {
        $players = [];
        foreach ($state->players as $player) {
            $players[$player->id] = $player;
        }
        $values = [];
        foreach ($state->players as $player) {
            $buchholz = Score::zero();
            $sonnebornBerger = Score::zero();
            foreach ($player->results as $result) {
                if ($result['played'] && $result['opponentId'] !== null) {
                    $opponent = $players[$result['opponentId']];
                    $opponentScore = $opponent->score;
                    $trailingZeroByes = $this->trailingZeroPointByes($opponent);
                    for ($index = 1; $index < $trailingZeroByes; ++$index) {
                        $opponentScore = $opponentScore->add($state->pointsForDraw);
                    }
                } else {
                    $opponentScore = $player->score;
                }
                $buchholz = $buchholz->add($opponentScore);
                $earned = $this->rules->pointsForResult($state, $result['result']);
                $sonnebornBerger = $sonnebornBerger->add($earned->multiply($opponentScore));
            }
            $values[$player->id] = new TieBreakValue($buchholz, $sonnebornBerger);
        }

        return $values;
    }

    private function trailingZeroPointByes(Player $player): int
    {
        $count = 0;
        for ($index = count($player->results) - 1; $index >= 0; --$index) {
            if (strtoupper($player->results[$index]['result']) !== 'Z') {
                break;
            }
            ++$count;
        }

        return $count;
    }
}
