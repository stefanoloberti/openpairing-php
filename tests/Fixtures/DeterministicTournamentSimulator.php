<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Fixtures;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;

final class DeterministicTournamentSimulator
{
    public static function initial(int $count, int $rounds, string $pairingSystem = 'fide-dutch-2026'): TournamentState
    {
        $players = [];
        for ($tpn = 1; $tpn <= $count; ++$tpn) {
            $players[] = new Player(
                id: $tpn,
                pairingNumber: $tpn,
                name: 'Synthetic Player '.$tpn,
                rating: 2401 - $tpn,
                title: '',
                team: '',
                club: '',
                active: true,
                score: Score::zero(),
                rank: $tpn,
            );
        }

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 1,
            numberOfRounds: $rounds,
            pairingSystem: $pairingSystem,
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
            tournamentName: sprintf('Synthetic %d player corpus', $count),
            pointsForPairingAllocatedBye: new Score(1, 1),
        );
    }

    public static function advance(TournamentState $state, PairingResult $pairing): TournamentState
    {
        $updates = [];
        foreach ($pairing->tables as $table) {
            $draw = ($state->round + $table->board) % 3 === 0;
            $whiteWins = ($state->round + $table->board) % 2 === 0;
            $updates[$table->whitePlayerId] = [
                'opponent' => $table->blackPlayerId,
                'colour' => Colour::White,
                'code' => $draw ? '=' : ($whiteWins ? '1' : '0'),
                'points' => $draw ? $state->pointsForDraw : ($whiteWins ? $state->pointsForWin : $state->lossScore()),
            ];
            $updates[$table->blackPlayerId] = [
                'opponent' => $table->whitePlayerId,
                'colour' => Colour::Black,
                'code' => $draw ? '=' : ($whiteWins ? '0' : '1'),
                'points' => $draw ? $state->pointsForDraw : ($whiteWins ? $state->lossScore() : $state->pointsForWin),
            ];
        }
        if ($pairing->byePlayerId !== null) {
            $updates[$pairing->byePlayerId] = [
                'opponent' => null,
                'colour' => null,
                'code' => 'U',
                'points' => $state->pairingAllocatedByeScore(),
            ];
        }

        $floatById = [];
        foreach ($pairing->floatAssignments as $assignment) {
            $floatById[$assignment->playerId] = $assignment->direction->value;
        }
        $players = [];
        foreach ($state->players as $player) {
            $update = $updates[$player->id];
            $opponents = $player->opponentHistory;
            $colours = $player->colourHistory;
            $floatHistory = $player->floatHistory;
            $receivedPab = $player->receivedPairingAllocatedBye;
            if ($update['opponent'] !== null) {
                $opponents[] = $update['opponent'];
                $colours[] = $update['colour'];
                $floatHistory[] = $floatById[$player->id] ?? 'none';
            } else {
                $floatHistory[] = $floatById[$player->id] ?? 'none';
                $receivedPab = true;
            }
            $results = $player->results;
            $results[] = [
                'round' => $state->round,
                'opponentId' => $update['opponent'],
                'colour' => $update['colour']?->value,
                'result' => $update['code'],
                'played' => $update['opponent'] !== null,
            ];
            $players[] = new Player(
                id: $player->id,
                pairingNumber: $player->pairingNumber,
                name: $player->name,
                rating: $player->rating,
                title: $player->title,
                team: $player->team,
                club: $player->club,
                active: $player->active,
                score: $player->score->add($update['points']),
                opponentHistory: $opponents,
                colourHistory: $colours,
                results: $results,
                receivedPairingAllocatedBye: $receivedPab,
                floatHistory: $floatHistory,
                acceleration: $player->acceleration,
                sex: $player->sex,
                federation: $player->federation,
                fideId: $player->fideId,
                birthDate: $player->birthDate,
                rank: $player->rank,
            );
        }

        return new TournamentState(
            schemaVersion: $state->schemaVersion,
            round: $state->round + 1,
            numberOfRounds: $state->numberOfRounds,
            pairingSystem: $state->pairingSystem,
            topColour: $state->topColour,
            pointsForWin: $state->pointsForWin,
            pointsForDraw: $state->pointsForDraw,
            maxPlayers: $state->maxPlayers,
            players: $players,
            tournamentName: $state->tournamentName,
            pointsForLoss: $state->pointsForLoss,
            pointsForZeroPointBye: $state->pointsForZeroPointBye,
            pointsForPairingAllocatedBye: $state->pointsForPairingAllocatedBye,
            pointsForUnknown: $state->pointsForUnknown,
            city: $state->city,
            federation: $state->federation,
            startDate: $state->startDate,
            endDate: $state->endDate,
            tournamentType: $state->tournamentType,
            chiefArbiter: $state->chiefArbiter,
            deputyArbiters: $state->deputyArbiters,
            timeControlDescription: $state->timeControlDescription,
            roundDates: $state->roundDates,
            pairingController: $state->pairingController,
            tieBreaksRecord: $state->tieBreaksRecord,
            tieBreaks: $state->tieBreaks,
            encodedTimeControl: $state->encodedTimeControl,
        );
    }
}
