<?php

declare(strict_types=1);

namespace OpenPairing\Simulation;

use OpenPairing\Application\PairingEngineInterface;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\TieBreak\FideTieBreakRanker;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchPairingEngine;

/** Public deterministic random-tournament generator for reproducible TRF26 tests. */
final readonly class RandomTournamentGenerator
{
    public function __construct(private FideTieBreakRanker $ranker = new FideTieBreakRanker())
    {
    }

    public function generate(
        int $playerCount,
        int $rounds,
        int $seed,
        string $pairingSystem = 'fide-dutch-2026',
        Colour $topColour = Colour::White,
        int $drawPermille = 300,
        bool $baku = false,
    ): TournamentState {
        if ($playerCount < 2 || $playerCount > TournamentState::MAX_PLAYERS) {
            throw new InvalidTournamentState('rtg.players', sprintf('RTG player count must be between 2 and %d.', TournamentState::MAX_PLAYERS));
        }
        if ($rounds < 1 || $rounds > TournamentState::MAX_ROUNDS || $rounds >= $playerCount) {
            throw new InvalidTournamentState('rtg.rounds', sprintf('RTG rounds must be between 1 and min(%d, players - 1).', TournamentState::MAX_ROUNDS));
        }
        if ($seed < 1 || $seed > 2_147_483_646) {
            throw new InvalidTournamentState('rtg.seed', 'RTG seed must be between 1 and 2147483646.');
        }
        if ($drawPermille < 0 || $drawPermille > 1_000) {
            throw new InvalidTournamentState('rtg.draw_rate', 'RTG draw rate must be between 0 and 1000 per mille.');
        }
        $engine = match ($pairingSystem) {
            'fide-dutch-2026' => new DutchPairingEngine(),
            'fide-burstein-2026' => new BursteinPairingEngine(),
            default => throw new InvalidTournamentState('rtg.pairing_system', 'RTG supports FIDE Dutch 2026 and FIDE Burstein 2026.'),
        };
        $state = $this->initialState($playerCount, $rounds, $seed, $pairingSystem, $topColour, $baku);
        $random = new ParkMillerRandom($seed);
        while ($state->round <= $rounds) {
            $pairing = $engine->pair($state);
            $this->assertComplete($state, $pairing);
            $state = $this->advance($state, $pairing, $random, $drawPermille);
        }

        return $this->withFinalRanks($state);
    }

    private function initialState(
        int $playerCount,
        int $rounds,
        int $seed,
        string $pairingSystem,
        Colour $topColour,
        bool $baku,
    ): TournamentState {
        $win = new Score(1, 1);
        $draw = new Score(1, 2);
        $gaCount = $baku ? min($playerCount, 2 * intdiv($playerCount + 3, 4)) : 0;
        $acceleratedRounds = intdiv($rounds + 1, 2);
        $fullVirtualRounds = intdiv($acceleratedRounds + 1, 2);
        $players = [];
        for ($tpn = 1; $tpn <= $playerCount; ++$tpn) {
            $schedule = [];
            if ($tpn <= $gaCount) {
                for ($round = 1; $round <= $rounds; ++$round) {
                    $schedule[] = $round <= $fullVirtualRounds
                        ? $win
                        : ($round <= $acceleratedRounds ? $draw : null);
                }
            }
            $players[] = new Player(
                id: $tpn,
                pairingNumber: $tpn,
                name: sprintf('RTG Player %04d', $tpn),
                rating: max(0, 2_601 - $tpn),
                title: '',
                team: '',
                club: '',
                active: true,
                score: Score::zero(),
                acceleration: $schedule[0] ?? null,
                rank: $tpn,
                accelerationSchedule: $schedule,
            );
        }

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 1,
            numberOfRounds: $rounds,
            pairingSystem: $pairingSystem,
            topColour: $topColour,
            pointsForWin: $win,
            pointsForDraw: $draw,
            maxPlayers: TournamentState::MAX_PLAYERS,
            players: $players,
            tournamentName: sprintf('OpenPairing RTG p%d r%d seed%d%s', $playerCount, $rounds, $seed, $baku ? ' Baku' : ''),
            pointsForLoss: Score::zero(),
            pointsForZeroPointBye: Score::zero(),
            pointsForPairingAllocatedBye: $win,
            pointsForUnknown: $draw,
            pairingController: sprintf('OpenPairingPHP RTG seed %d', $seed),
            tieBreaksRecord: '202',
            tieBreaks: 'BH',
        );
    }

    private function assertComplete(TournamentState $state, PairingResult $pairing): void
    {
        if ($pairing->violations !== [] || $pairing->unpairedPlayerIds !== []) {
            throw new InvalidTournamentState('rtg.pairing_failed', sprintf('RTG could not complete round %d.', $state->round));
        }
        $accounted = (2 * count($pairing->tables)) + ($pairing->byePlayerId === null ? 0 : 1);
        if ($accounted !== count($state->activePlayers())) {
            throw new InvalidTournamentState('rtg.pairing_incomplete', sprintf('RTG round %d did not account for every active player.', $state->round));
        }
    }

    private function advance(
        TournamentState $state,
        PairingResult $pairing,
        ParkMillerRandom $random,
        int $drawPermille,
    ): TournamentState {
        $updates = [];
        foreach ($pairing->tables as $table) {
            $roll = $random->nextModulo(1_000);
            $draw = $roll < $drawPermille;
            $whiteWins = !$draw && $random->nextModulo(2) === 0;
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
            $update = $updates[$player->id] ?? throw new InvalidTournamentState('rtg.player_missing', sprintf('RTG round %d omitted player %d.', $state->round, $player->id));
            $opponents = $player->opponentHistory;
            $colours = $player->colourHistory;
            $floatHistory = $player->floatHistory;
            $receivedPab = $player->receivedPairingAllocatedBye;
            if ($update['opponent'] !== null) {
                $opponents[] = $update['opponent'];
                $colours[] = $update['colour'];
            } else {
                $receivedPab = true;
            }
            $floatHistory[] = $floatById[$player->id] ?? 'none';
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
                active: true,
                score: $player->score->add($update['points']),
                opponentHistory: $opponents,
                colourHistory: $colours,
                results: $results,
                receivedPairingAllocatedBye: $receivedPab,
                floatHistory: $floatHistory,
                acceleration: $player->accelerationForRound($state->round + 1),
                sex: $player->sex,
                federation: $player->federation,
                fideId: $player->fideId,
                birthDate: $player->birthDate,
                rank: $player->rank,
                accelerationSchedule: $player->accelerationSchedule,
            );
        }

        return $this->copyState($state, $players, $state->round + 1);
    }

    private function withFinalRanks(TournamentState $state): TournamentState
    {
        $ranks = [];
        foreach ($this->ranker->rankFromTournamentRecord($state)->standings($state) as $row) {
            $ranks[$row['playerId']] = $row['rank'];
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
                acceleration: $player->acceleration,
                sex: $player->sex,
                federation: $player->federation,
                fideId: $player->fideId,
                birthDate: $player->birthDate,
                rank: $ranks[$player->id],
                accelerationSchedule: $player->accelerationSchedule,
            ),
            $state->players,
        );

        return $this->copyState($state, $players, $state->round);
    }

    /** @param list<Player> $players */
    private function copyState(TournamentState $state, array $players, int $round): TournamentState
    {
        return new TournamentState(
            schemaVersion: $state->schemaVersion,
            round: $round,
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

/** Stable Park-Miller PRNG: reproducible across supported 64-bit PHP runtimes. */
final class ParkMillerRandom
{
    public function __construct(private int $state)
    {
    }

    public function nextModulo(int $modulus): int
    {
        $this->state = (int) (($this->state * 48_271) % 2_147_483_647);

        return $this->state % $modulus;
    }
}
