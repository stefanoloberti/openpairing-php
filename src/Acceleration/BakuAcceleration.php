<?php

declare(strict_types=1);

namespace OpenPairing\Acceleration;

use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;

/** FIDE C.04.7 Baku Acceleration Method, effective 1 February 2026. */
final readonly class BakuAcceleration
{
    /**
     * Applies the virtual points for the round that is about to be paired.
     *
     * The optional GA-last player identity preserves Article 1.3.2 when late
     * entries have changed the participant list after round one.
     */
    public function apply(TournamentState $state, ?int $gaLastPlayerId = null): TournamentState
    {
        $this->assertApplicableScoring($state);
        $active = $state->activePlayers();
        usort($active, static fn (Player $left, Player $right): int => $left->pairingNumber <=> $right->pairingNumber);
        $gaCount = $this->gaCount($active, $gaLastPlayerId);
        $virtual = $this->virtualPointsForRound($state);
        $gaIds = [];
        if ($virtual !== null) {
            foreach (array_slice($active, 0, $gaCount) as $player) {
                $gaIds[$player->id] = true;
            }
        }

        $players = array_map(
            static fn (Player $player): Player => new Player(
                id: $player->id,
                pairingNumber: $player->pairingNumber,
                name: $player->name,
                rating: $player->rating,
                title: $player->title,
                team: $player->team,
                club: $player->club,
                active: $player->active,
                score: $player->score,
                opponentHistory: $player->opponentHistory,
                colourHistory: $player->colourHistory,
                results: $player->results,
                receivedPairingAllocatedBye: $player->receivedPairingAllocatedBye,
                floatHistory: $player->floatHistory,
                acceleration: isset($gaIds[$player->id]) ? $virtual : null,
                sex: $player->sex,
                federation: $player->federation,
                fideId: $player->fideId,
                birthDate: $player->birthDate,
                rank: $player->rank,
            ),
            $state->players,
        );

        return new TournamentState(
            schemaVersion: $state->schemaVersion,
            round: $state->round,
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

    /** @param list<Player> $active */
    private function gaCount(array $active, ?int $gaLastPlayerId): int
    {
        if ($gaLastPlayerId === null) {
            return min(count($active), 2 * intdiv(count($active) + 3, 4));
        }
        foreach ($active as $index => $player) {
            if ($player->id === $gaLastPlayerId) {
                return $index + 1;
            }
        }

        throw new InvalidTournamentState('acceleration.ga_last_unknown', 'The preserved last GA participant is not active in this round.');
    }

    private function virtualPointsForRound(TournamentState $state): ?Score
    {
        $acceleratedRounds = intdiv($state->numberOfRounds + 1, 2);
        if ($state->round > $acceleratedRounds) {
            return null;
        }
        $fullVirtualRounds = intdiv($acceleratedRounds + 1, 2);

        return $state->round <= $fullVirtualRounds
            ? $state->pointsForWin
            : $state->pointsForDraw;
    }

    private function assertApplicableScoring(TournamentState $state): void
    {
        if ($state->lossScore()->compare(Score::zero()) !== 0
            || $state->pointsForWin->compare($state->pointsForDraw->add($state->pointsForDraw)) !== 0) {
            throw new InvalidTournamentState(
                'acceleration.scoring_unsupported',
                'Baku acceleration requires win points equal to two draws and zero points for a loss.',
            );
        }
    }
}
