<?php

declare(strict_types=1);

namespace OpenPairing\Verification;

use OpenPairing\Application\PairingEngineInterface;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\TieBreak\FideTieBreakRanker;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchFloatClassifier;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Engine\Dutch\DutchRules;
use OpenPairing\Format\Trf\Trf2026Codec;

/** Public, oracle-free TRF26 pairing and tie-break checker. */
final readonly class TrfPairingChecker
{
    public function __construct(
        private Trf2026Codec $codec = new Trf2026Codec(),
        private DutchRules $rules = new DutchRules(),
        private FideTieBreakRanker $tieBreakRanker = new FideTieBreakRanker(),
    ) {
    }

    /**
     * @return array{
     *   schema: string,
     *   status: string,
     *   pairingSystem: string,
     *   tournamentName: string,
     *   caseCount: int,
     *   counts: array{
     *     roundsChecked: int,
     *     roundsExact: int,
     *     roundsSamePairsColourVariant: int,
     *     roundsPairingDeviation: int,
     *     roundsEngineFailure: int
     *   },
     *   rounds: list<array<string, mixed>>,
     *   tieBreaks: array{
     *     status: string,
     *     record: string,
     *     rankOrder: string,
     *     playersChecked: int,
     *     mismatches: list<array{playerId: int, reportedRank: int, calculatedRank: int}>
     *   }
     * }
     */
    public function check(string $trf): array
    {
        $full = $this->codec->decode($trf);
        $engine = $this->engine($full);
        $scoresEntering = $this->scoresEnteringRounds($full);
        $roundReports = [];
        $counts = [
            'roundsChecked' => 0,
            'roundsExact' => 0,
            'roundsSamePairsColourVariant' => 0,
            'roundsPairingDeviation' => 0,
            'roundsEngineFailure' => 0,
        ];

        for ($round = 1; $round <= $full->numberOfRounds; ++$round) {
            $reference = $this->referenceRound($full, $round);
            if ($reference['pairs'] === [] && $reference['bye'] === null) {
                continue;
            }
            ++$counts['roundsChecked'];
            $state = $this->historicalState($full, $round, $scoresEntering);
            $generatedResult = $engine->pair($state);
            $generated = $this->generatedRound($generatedResult);
            $engineFailure = $generatedResult->violations !== [] || $generatedResult->unpairedPlayerIds !== [];
            if ($engineFailure) {
                $status = 'engine-failure';
                ++$counts['roundsEngineFailure'];
            } elseif ($reference === $generated) {
                $status = 'exact';
                ++$counts['roundsExact'];
            } elseif (array_keys($reference['pairs']) === array_keys($generated['pairs'])
                && $reference['bye'] === $generated['bye']) {
                $status = 'same-pairs-colour-variant';
                ++$counts['roundsSamePairsColourVariant'];
            } else {
                $status = 'pairing-deviation';
                ++$counts['roundsPairingDeviation'];
            }
            $report = [
                'round' => $round,
                'status' => $status,
                'referencePairCount' => count($reference['pairs']),
                'generatedPairCount' => count($generated['pairs']),
                'referenceByePlayerId' => $reference['bye'],
                'generatedByePlayerId' => $generated['bye'],
            ];
            if ($status !== 'exact') {
                $report['reference'] = $reference;
                $report['generated'] = $generated;
                $report['violations'] = array_map(
                    static fn ($diagnostic): array => $diagnostic->toArray(),
                    $generatedResult->violations,
                );
                $report['unpairedPlayerIds'] = $generatedResult->unpairedPlayerIds;
            }
            $roundReports[] = $report;
        }

        $tieBreaks = $this->checkTieBreaks($full);
        $caseCount = $counts['roundsChecked'] + $tieBreaks['playersChecked'];
        if ($caseCount === 0) {
            throw new InvalidTournamentState('ptc.no_cases', 'The PTC input contains no pairing round or declared tie-break ranking to check.');
        }
        $exact = $counts['roundsSamePairsColourVariant'] === 0
            && $counts['roundsPairingDeviation'] === 0
            && $counts['roundsEngineFailure'] === 0
            && $tieBreaks['status'] !== 'mismatch';

        return [
            'schema' => 'openpairing.ptc-report/1',
            'status' => $exact ? 'exact' : 'discrepancy',
            'pairingSystem' => $full->pairingSystem,
            'tournamentName' => $full->tournamentName,
            'caseCount' => $caseCount,
            'counts' => $counts,
            'rounds' => $roundReports,
            'tieBreaks' => $tieBreaks,
        ];
    }

    private function engine(TournamentState $state): PairingEngineInterface
    {
        return match ($state->pairingSystem) {
            'fide-dutch-2026' => new DutchPairingEngine(),
            'fide-burstein-2026' => new BursteinPairingEngine(),
            default => throw new InvalidTournamentState('ptc.pairing_system', 'The PTC supports FIDE Dutch 2026 and FIDE Burstein 2026 reports.'),
        };
    }

    /** @return array<int, array<int, Score>> */
    private function scoresEnteringRounds(TournamentState $state): array
    {
        $scores = [];
        foreach ($state->players as $player) {
            $current = Score::zero();
            $byRound = [];
            foreach ($player->results as $result) {
                $byRound[$result['round']] = $result;
            }
            for ($round = 1; $round <= $state->numberOfRounds + 1; ++$round) {
                $scores[$player->id][$round] = $current;
                if (isset($byRound[$round])) {
                    $current = $current->add($this->rules->pointsForResult($state, $byRound[$round]['result']));
                }
            }
        }

        return $scores;
    }

    /** @param array<int, array<int, Score>> $scoresEntering */
    private function historicalState(TournamentState $full, int $round, array $scoresEntering): TournamentState
    {
        $floatClassifier = new DutchFloatClassifier($this->rules);
        $playersById = [];
        foreach ($full->players as $knownPlayer) {
            $playersById[$knownPlayer->id] = $knownPlayer;
        }
        $players = [];
        foreach ($full->players as $player) {
            $previous = array_values(array_filter($player->results, static fn (array $result): bool => $result['round'] < $round));
            $current = null;
            foreach ($player->results as $result) {
                if ($result['round'] === $round) {
                    $current = $result;
                    break;
                }
            }
            $opponents = [];
            $colours = [];
            $floats = [];
            $receivedPab = false;
            foreach ($previous as $result) {
                if ($result['played'] && $result['opponentId'] !== null) {
                    $opponents[] = $result['opponentId'];
                    $colour = Colour::tryFrom((string) $result['colour']);
                    if ($colour !== null) {
                        $colours[] = $colour;
                    }
                }
                $historyRound = $result['round'];
                $playerScore = $scoresEntering[$player->id][$historyRound];
                $playerAcceleration = $player->accelerationForRound($historyRound);
                if ($playerAcceleration !== null) {
                    $playerScore = $playerScore->add($playerAcceleration);
                }
                $opponentScore = null;
                if ($result['opponentId'] !== null) {
                    $opponentScore = $scoresEntering[$result['opponentId']][$historyRound];
                    $opponentAcceleration = $playersById[$result['opponentId']]->accelerationForRound($historyRound);
                    if ($opponentAcceleration !== null) {
                        $opponentScore = $opponentScore->add($opponentAcceleration);
                    }
                }
                $floats[] = $floatClassifier->direction(
                    $full,
                    $playerScore,
                    $opponentScore,
                    (string) $result['result'],
                    (bool) $result['played'],
                );
                $receivedPab = $receivedPab || strtoupper($result['result']) === 'U';
            }
            $active = $current !== null
                && ($current['opponentId'] !== null || strtoupper($current['result']) === 'U');
            $players[] = new Player(
                id: $player->id,
                pairingNumber: $player->pairingNumber,
                name: $player->name,
                rating: $player->rating,
                title: $player->title,
                team: $player->team,
                club: $player->club,
                active: $active,
                score: $scoresEntering[$player->id][$round],
                opponentHistory: $opponents,
                colourHistory: $colours,
                results: $previous,
                receivedPairingAllocatedBye: $receivedPab,
                floatHistory: $floats,
                acceleration: $player->accelerationSchedule === []
                    ? $player->acceleration
                    : $player->accelerationForRound($round),
                sex: $player->sex,
                federation: $player->federation,
                fideId: $player->fideId,
                birthDate: $player->birthDate,
                rank: $player->rank,
                accelerationSchedule: $player->accelerationSchedule,
            );
        }

        return new TournamentState(
            schemaVersion: $full->schemaVersion,
            round: $round,
            numberOfRounds: $full->numberOfRounds,
            pairingSystem: $full->pairingSystem,
            topColour: $full->topColour,
            pointsForWin: $full->pointsForWin,
            pointsForDraw: $full->pointsForDraw,
            maxPlayers: $full->maxPlayers,
            players: $players,
            tournamentName: $full->tournamentName,
            pointsForLoss: $full->pointsForLoss,
            pointsForZeroPointBye: $full->pointsForZeroPointBye,
            pointsForPairingAllocatedBye: $full->pointsForPairingAllocatedBye,
            pointsForUnknown: $full->pointsForUnknown,
        );
    }

    /** @return array{pairs: array<string, string>, bye: int|null} */
    private function referenceRound(TournamentState $state, int $round): array
    {
        $pairs = [];
        $bye = null;
        foreach ($state->players as $player) {
            foreach ($player->results as $result) {
                if ($result['round'] !== $round) {
                    continue;
                }
                if (strtoupper($result['result']) === 'U') {
                    if ($bye !== null && $bye !== $player->id) {
                        throw new InvalidTournamentState('ptc.multiple_byes', sprintf('Round %d contains more than one pairing-allocated bye.', $round));
                    }
                    $bye = $player->id;
                }
                $opponent = $result['opponentId'];
                if ($opponent === null || $player->id > $opponent) {
                    continue;
                }
                $colour = Colour::tryFrom((string) $result['colour']);
                if ($colour === null) {
                    throw new InvalidTournamentState('ptc.reference_colour', sprintf('Round %d pairing %d-%d has no scheduled colour.', $round, $player->id, $opponent));
                }
                $key = $player->id.'-'.$opponent;
                $pairs[$key] = $colour === Colour::White
                    ? $player->id.'>'.$opponent
                    : $opponent.'>'.$player->id;
            }
        }
        ksort($pairs, SORT_NATURAL);

        return ['pairs' => $pairs, 'bye' => $bye];
    }

    /** @return array{pairs: array<string, string>, bye: int|null} */
    private function generatedRound(PairingResult $result): array
    {
        $pairs = [];
        foreach ($result->tables as $table) {
            $low = min($table->whitePlayerId, $table->blackPlayerId);
            $high = max($table->whitePlayerId, $table->blackPlayerId);
            $pairs[$low.'-'.$high] = $table->whitePlayerId.'>'.$table->blackPlayerId;
        }
        ksort($pairs, SORT_NATURAL);

        return ['pairs' => $pairs, 'bye' => $result->byePlayerId];
    }

    /** @return array{status: string, record: string, rankOrder: string, playersChecked: int, mismatches: list<array{playerId: int, reportedRank: int, calculatedRank: int}>} */
    private function checkTieBreaks(TournamentState $state): array
    {
        if ($state->tieBreaksRecord === '' || $state->tieBreaks === '') {
            return [
                'status' => 'not-declared',
                'record' => '',
                'rankOrder' => '',
                'playersChecked' => 0,
                'mismatches' => [],
            ];
        }
        $reported = [];
        foreach ($state->players as $player) {
            $reported[$player->id] = $player->rank;
        }
        $mismatches = [];
        $standings = $this->tieBreakRanker->rankFromTournamentRecord($state)->standings($state);
        foreach ($standings as $row) {
            $reportedRank = $reported[$row['playerId']];
            if ($reportedRank !== $row['rank']) {
                $mismatches[] = [
                    'playerId' => $row['playerId'],
                    'reportedRank' => $reportedRank,
                    'calculatedRank' => $row['rank'],
                ];
            }
        }

        return [
            'status' => $mismatches === [] ? 'exact' : 'mismatch',
            'record' => $state->tieBreaksRecord,
            'rankOrder' => $state->tieBreaks,
            'playersChecked' => count($standings),
            'mismatches' => $mismatches,
        ];
    }
}
