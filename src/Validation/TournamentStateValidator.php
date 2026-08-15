<?php

declare(strict_types=1);

namespace OpenPairing\Validation;

use InvalidArgumentException;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\TieBreak\FideTieBreakCode;
use OpenPairing\Domain\TieBreak\FideTieBreakDefinitionValidator;
use OpenPairing\Domain\TieBreak\TieBreakDescriptorParser;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchRules;

final class TournamentStateValidator
{
    public function __construct(private readonly DutchRules $rules = new DutchRules())
    {
    }

    public function validate(TournamentState $state): void
    {
        if ($state->schemaVersion !== TournamentState::SCHEMA_VERSION) {
            throw new InvalidTournamentState('schema.unsupported', 'Unsupported tournament schema version.');
        }
        if (!in_array($state->pairingSystem, ['fide-dutch-2026', 'fide-burstein-2026', 'fide-round-robin'], true)) {
            throw new InvalidTournamentState('pairing_system.unsupported', 'The pairing system is not supported.');
        }
        if (($state->tieBreaksRecord === '') !== ($state->tieBreaks === '')) {
            throw new InvalidTournamentState('tiebreak.record_incomplete', 'Tie-break record and descriptor list must either both be present or both be empty.');
        }
        if ($state->tieBreaksRecord !== '') {
            if (!in_array($state->tieBreaksRecord, ['202', '212'], true)) {
                throw new InvalidTournamentState('tiebreak.record_unknown', 'Tie-break metadata must use TRF record 202 or 212.');
            }
            try {
                $tieBreakDefinitions = (new TieBreakDescriptorParser())->parseList($state->tieBreaks);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidTournamentState('tiebreak.descriptor_invalid', $exception->getMessage());
            }
            if ($state->tieBreaksRecord === '202') {
                foreach ($tieBreakDefinitions as $definition) {
                    if ($definition->code === FideTieBreakCode::Points) {
                        throw new InvalidTournamentState('tiebreak.pts_in_202', 'PTS is available only in a TRF 212 complete standings list.');
                    }
                }
            }
            try {
                foreach ($tieBreakDefinitions as $definition) {
                    (new FideTieBreakDefinitionValidator())->validateForTournament(
                        $definition,
                        $state->pairingSystem === 'fide-round-robin',
                    );
                }
            } catch (InvalidArgumentException $exception) {
                throw new InvalidTournamentState('tiebreak.not_applicable', $exception->getMessage());
            }
        }
        if ($state->numberOfRounds < 1 || $state->numberOfRounds > TournamentState::MAX_ROUNDS || $state->round < 1 || $state->round > $state->numberOfRounds + 1) {
            throw new InvalidTournamentState('round.range', 'Round values are outside the supported tournament range.');
        }
        if ($state->maxPlayers < 0 || $state->maxPlayers > TournamentState::MAX_PLAYERS || count($state->players) > $state->maxPlayers) {
            throw new InvalidTournamentState('players.limit', 'Player count exceeds the configured limit.');
        }

        /** @var array<int, Player> $playersById */
        $playersById = [];
        $pairingNumbers = [];
        /** @var array<int, array<int, array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool}>> $roundsByPlayer */
        $roundsByPlayer = [];
        foreach ($state->players as $player) {
            if ($player->id < 1 || $player->pairingNumber < 1 || $player->pairingNumber > TournamentState::MAX_PAIRING_NUMBER) {
                throw new InvalidTournamentState('player.identifier', 'Player IDs and TPNs must be positive.');
            }
            if (isset($playersById[$player->id]) || isset($pairingNumbers[$player->pairingNumber])) {
                throw new InvalidTournamentState('player.duplicate', 'Player IDs and TPNs must be unique.');
            }
            if ($player->acceleration !== null && $player->acceleration->compare(Score::zero()) < 0) {
                throw new InvalidTournamentState('acceleration.negative', sprintf('Player %d has negative virtual points.', $player->pairingNumber));
            }
            if (count($player->accelerationSchedule) > $state->numberOfRounds) {
                throw new InvalidTournamentState('acceleration.round_limit', sprintf('Player %d has virtual points beyond the tournament rounds.', $player->pairingNumber));
            }
            foreach ($player->accelerationSchedule as $roundIndex => $virtualPoints) {
                if ($virtualPoints !== null && $virtualPoints->compare(Score::zero()) < 0) {
                    throw new InvalidTournamentState('acceleration.negative', sprintf('Player %d has negative virtual points in round %d.', $player->pairingNumber, $roundIndex + 1));
                }
            }
            if (count($player->floatHistory) !== count($player->results)) {
                throw new InvalidTournamentState('float_history.mismatch', sprintf('Player %d float history does not cover every completed result.', $player->pairingNumber));
            }
            foreach ($player->floatHistory as $float) {
                if (!in_array($float, ['none', 'up', 'down'], true)) {
                    throw new InvalidTournamentState('float_history.value', sprintf('Player %d has an unsupported float-history value.', $player->pairingNumber));
                }
            }
            $playersById[$player->id] = $player;
            $pairingNumbers[$player->pairingNumber] = true;
            foreach ($player->results as $result) {
                $round = $result['round'];
                if ($round < 1 || $round >= $state->round || $round > $state->numberOfRounds) {
                    throw new InvalidTournamentState('result.round', sprintf('Player %d has a result outside completed rounds.', $player->pairingNumber));
                }
                if (isset($roundsByPlayer[$player->id][$round])) {
                    throw new InvalidTournamentState('result.duplicate', sprintf('Player %d has duplicate round %d.', $player->pairingNumber, $round));
                }
                $roundsByPlayer[$player->id][$round] = $result;
            }
        }

        foreach ($state->prohibitedPairingGroups as $index => $group) {
            if ($group->firstRound < 1
                || $group->firstRound > $group->lastRound
                || $group->lastRound > $state->numberOfRounds) {
                throw new InvalidTournamentState('prohibited.round_range', sprintf('Prohibited-pairing group %d has an invalid round range.', $index + 1));
            }
            if (count($group->playerIds) < 2 || count($group->playerIds) > TournamentState::MAX_PLAYERS) {
                throw new InvalidTournamentState('prohibited.player_count', sprintf('Prohibited-pairing group %d must contain 2..%d players.', $index + 1, TournamentState::MAX_PLAYERS));
            }
            if (count(array_unique($group->playerIds)) !== count($group->playerIds)) {
                throw new InvalidTournamentState('prohibited.player_duplicate', sprintf('Prohibited-pairing group %d contains a duplicate player.', $index + 1));
            }
            foreach ($group->playerIds as $playerId) {
                if (!isset($playersById[$playerId])) {
                    throw new InvalidTournamentState('prohibited.player_unknown', sprintf('Prohibited-pairing group %d references unknown player %d.', $index + 1, $playerId));
                }
            }
        }

        $scheduledByeKeys = [];
        $scheduledPlayersByRound = [];
        foreach ($state->scheduledByes as $index => $scheduledBye) {
            if (!in_array($scheduledBye->type, ['F', 'H', 'Z'], true)) {
                throw new InvalidTournamentState('scheduled_bye.type', sprintf('Scheduled-bye record %d has an unsupported type.', $index + 1));
            }
            if ($scheduledBye->round < 1 || $scheduledBye->round > $state->numberOfRounds) {
                throw new InvalidTournamentState('scheduled_bye.round', sprintf('Scheduled-bye record %d has an invalid round.', $index + 1));
            }
            if ($scheduledBye->playerIds === [] || count($scheduledBye->playerIds) > TournamentState::MAX_PLAYERS) {
                throw new InvalidTournamentState('scheduled_bye.player_count', sprintf('Scheduled-bye record %d must contain 1..%d players.', $index + 1, TournamentState::MAX_PLAYERS));
            }
            if (count(array_unique($scheduledBye->playerIds)) !== count($scheduledBye->playerIds)) {
                throw new InvalidTournamentState('scheduled_bye.player_duplicate', sprintf('Scheduled-bye record %d contains a duplicate player.', $index + 1));
            }
            $recordKey = $scheduledBye->round.':'.$scheduledBye->type;
            if (isset($scheduledByeKeys[$recordKey])) {
                throw new InvalidTournamentState('scheduled_bye.record_duplicate', sprintf('Round %d has more than one scheduled-bye record of type %s.', $scheduledBye->round, $scheduledBye->type));
            }
            $scheduledByeKeys[$recordKey] = true;
            foreach ($scheduledBye->playerIds as $playerId) {
                if (!isset($playersById[$playerId])) {
                    throw new InvalidTournamentState('scheduled_bye.player_unknown', sprintf('Scheduled-bye record %d references unknown player %d.', $index + 1, $playerId));
                }
                if (isset($scheduledPlayersByRound[$scheduledBye->round][$playerId])) {
                    throw new InvalidTournamentState('scheduled_bye.player_conflict', sprintf('Player %d has more than one scheduled bye in round %d.', $playerId, $scheduledBye->round));
                }
                $scheduledPlayersByRound[$scheduledBye->round][$playerId] = true;
                if ($scheduledBye->round < $state->round) {
                    $completedResult = $roundsByPlayer[$playerId][$scheduledBye->round] ?? null;
                    if ($scheduledBye->type === 'Z' && $completedResult === null) {
                        continue;
                    }
                    if ($completedResult === null || strtoupper($completedResult['result']) !== $scheduledBye->type || $completedResult['opponentId'] !== null) {
                        throw new InvalidTournamentState('scheduled_bye.result_mismatch', sprintf('Player %d scheduled bye in completed round %d does not match result history.', $playerId, $scheduledBye->round));
                    }
                }
            }
        }

        foreach ($state->players as $player) {
            $calculatedScore = Score::zero();
            $opponentHistory = [];
            $colourHistory = [];
            $receivedPab = false;
            foreach ($roundsByPlayer[$player->id] ?? [] as $round => $result) {
                $code = strtoupper($result['result']);
                $played = in_array($code, ['W', 'D', 'L', '1', '=', '0'], true);
                if ($played !== $result['played']) {
                    throw new InvalidTournamentState('result.played', sprintf('Player %d round %d has an inconsistent played flag.', $player->pairingNumber, $round));
                }
                $opponentId = $result['opponentId'];
                if ($opponentId === null) {
                    if (!in_array($code, ['H', 'F', 'U', 'Z'], true) || $result['colour'] !== null) {
                        throw new InvalidTournamentState('result.bye', sprintf('Player %d round %d has an invalid bye.', $player->pairingNumber, $round));
                    }
                } else {
                    $opponent = $playersById[$opponentId] ?? throw new InvalidTournamentState('result.opponent_unknown', sprintf('Player %d references an unknown opponent.', $player->pairingNumber));
                    $reciprocal = $roundsByPlayer[$opponentId][$round] ?? null;
                    if ($reciprocal === null || $reciprocal['opponentId'] !== $player->id) {
                        throw new InvalidTournamentState('result.not_reciprocal', sprintf('Player %d round %d lacks a reciprocal result.', $player->pairingNumber, $round));
                    }
                    $this->validateReciprocal($player, $opponent, $round, $result, $reciprocal);
                    if ($played) {
                        $opponentHistory[] = $opponentId;
                        $colour = Colour::tryFrom((string) $result['colour']);
                        if ($colour === null) {
                            throw new InvalidTournamentState('result.colour', sprintf('Player %d round %d lacks a played colour.', $player->pairingNumber, $round));
                        }
                        $colourHistory[] = $colour;
                    }
                }
                $receivedPab = $receivedPab || $code === 'U';
                $calculatedScore = $calculatedScore->add($this->rules->pointsForResult($state, $code));
            }

            if ($calculatedScore->compare($player->score) !== 0) {
                throw new InvalidTournamentState('score.mismatch', sprintf('Player %d score does not equal completed results.', $player->pairingNumber));
            }
            if ($opponentHistory !== $player->opponentHistory || $colourHistory !== $player->colourHistory) {
                throw new InvalidTournamentState('history.mismatch', sprintf('Player %d histories do not equal completed played games.', $player->pairingNumber));
            }
            if ($state->pairingSystem !== 'fide-round-robin'
                && count($opponentHistory) !== count(array_unique($opponentHistory))) {
                throw new InvalidTournamentState('history.repeat_opponent', sprintf('Player %d has played an opponent more than once.', $player->pairingNumber));
            }
            if ($receivedPab !== $player->receivedPairingAllocatedBye) {
                throw new InvalidTournamentState('pab.mismatch', sprintf('Player %d PAB flag does not equal result history.', $player->pairingNumber));
            }
        }
    }

    /**
     * @param array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool} $result
     * @param array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool} $reciprocal
     */
    private function validateReciprocal(Player $player, Player $opponent, int $round, array $result, array $reciprocal): void
    {
        $code = strtoupper($result['result']);
        $expected = match ($code) {
            '1', 'W' => ['0', 'L'],
            '0', 'L' => ['1', 'W', '=', 'D', '0', 'L'],
            '=', 'D' => ['=', 'D', '0', 'L'],
            '+' => ['-'],
            '-' => ['+', '-'],
            default => throw new InvalidTournamentState('result.code', sprintf('Player %d round %d has an unsupported result.', $player->pairingNumber, $round)),
        };
        if (!in_array(strtoupper($reciprocal['result']), $expected, true)) {
            throw new InvalidTournamentState('result.incompatible', sprintf('Players %d and %d have incompatible results.', $player->pairingNumber, $opponent->pairingNumber));
        }
        if ($result['played']) {
            if ($result['colour'] === null || $reciprocal['colour'] === null || $result['colour'] === $reciprocal['colour']) {
                throw new InvalidTournamentState('result.colour', sprintf('Players %d and %d have incompatible colours.', $player->pairingNumber, $opponent->pairingNumber));
            }
        } elseif (($result['colour'] === null) !== ($reciprocal['colour'] === null)
            || ($result['colour'] !== null && $result['colour'] === $reciprocal['colour'])) {
            throw new InvalidTournamentState('result.colour', sprintf('Players %d and %d have incompatible scheduled colours for a forfeit.', $player->pairingNumber, $opponent->pairingNumber));
        }
    }
}
