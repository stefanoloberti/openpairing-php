<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Application\PairingEngineInterface;
use OpenPairing\Diagnostics\Diagnostic;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Pairing\FloatAssignment;
use OpenPairing\Domain\Pairing\FloatDirection;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Pairing\PairingTable;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Validation\TournamentStateValidator;

final class InitialDutchPairingEngine implements PairingEngineInterface
{
    public const VERSION = 'fide-dutch-2026.initial/1';

    public function pair(TournamentState $state): PairingResult
    {
        $players = $this->validateAndSort($state);
        $trace = [];
        $byePlayerId = null;

        if (count($players) % 2 === 1) {
            $bye = array_pop($players);
            if (!$bye instanceof Player) {
                throw new InvalidTournamentState('engine.internal', 'Could not select the bye player.');
            }

            $byePlayerId = $bye->id;
            $trace[] = [
                'step' => 1,
                'code' => 'pab.selected',
                'detail' => sprintf('Selected active TPN %d as the first-round PAB.', $bye->pairingNumber),
            ];
        }

        $half = intdiv(count($players), 2);
        $s1 = array_slice($players, 0, $half);
        $s2 = array_slice($players, $half);
        $tables = [];

        foreach ($s1 as $index => $higher) {
            $lower = $s2[$index];
            $higherColour = $index % 2 === 0 ? $state->topColour : $state->topColour->opposite();
            $white = $higherColour === Colour::White ? $higher : $lower;
            $black = $higherColour === Colour::Black ? $higher : $lower;
            $tables[] = new PairingTable($index + 1, $white->id, $black->id);
            $trace[] = [
                'step' => count($trace) + 1,
                'code' => 'pair.created',
                'detail' => sprintf('TPN %d paired with TPN %d.', $higher->pairingNumber, $lower->pairingNumber),
            ];
        }

        return new PairingResult(
            tables: $tables,
            byePlayerId: $byePlayerId,
            unpairedPlayerIds: [],
            diagnostics: [new Diagnostic(
                'engine.initial_slice',
                'Applied the supported FIDE Dutch 2026 initial-round slice.',
                context: ['activePlayers' => count($state->activePlayers())],
            )],
            violations: [],
            qualityVector: [],
            trace: $trace,
            algorithmVersion: self::VERSION,
            floatAssignments: $byePlayerId === null
                ? []
                : [new FloatAssignment($byePlayerId, FloatDirection::Down)],
        );
    }

    /** @return list<Player> */
    private function validateAndSort(TournamentState $state): array
    {
        (new TournamentStateValidator())->validate($state);
        if ($state->schemaVersion !== TournamentState::SCHEMA_VERSION) {
            throw new InvalidTournamentState('schema.unsupported', 'Unsupported tournament schema version.');
        }

        if ($state->pairingSystem !== 'fide-dutch-2026') {
            throw new InvalidTournamentState('pairing_system.unsupported', 'Only fide-dutch-2026 is supported.');
        }

        if ($state->round !== 1) {
            throw new InvalidTournamentState('round.unsupported', 'The milestone engine supports round 1 only.');
        }

        $players = $state->activePlayers();
        if (count($players) > $state->maxPlayers) {
            throw new InvalidTournamentState('players.limit', 'Active player count exceeds the configured limit.');
        }

        $ids = [];
        $tpns = [];
        foreach ($state->players as $player) {
            if ($player->id < 1 || $player->pairingNumber < 1) {
                throw new InvalidTournamentState('player.identifier', 'Player IDs and TPNs must be positive.');
            }

            if (isset($ids[$player->id]) || isset($tpns[$player->pairingNumber])) {
                throw new InvalidTournamentState('player.duplicate', 'Player IDs and TPNs must be unique.');
            }

            if ($player->hasPlayedRound() || $player->score->compare(\OpenPairing\Domain\Score\Score::zero()) !== 0) {
                throw new InvalidTournamentState('round.not_initial', 'Initial slice requires empty histories and zero scores.');
            }

            if ($player->receivedPairingAllocatedBye) {
                throw new InvalidTournamentState('round.not_initial', 'Initial slice cannot contain a previous PAB.');
            }

            $ids[$player->id] = true;
            $tpns[$player->pairingNumber] = true;
        }

        usort(
            $players,
            static fn (Player $left, Player $right): int => $left->pairingNumber <=> $right->pairingNumber,
        );

        return $players;
    }

}
