#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Diagnostics\RuntimeCodeIdentity;
use OpenPairing\Diagnostics\CorpusIdentity;
use OpenPairing\Diagnostics\ShadowOraclePolicy;
use OpenPairing\Diagnostics\ShadowPairingComparison;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Engine\Dutch\DutchRules;
use OpenPairing\Engine\Dutch\DutchFloatClassifier;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchPreliminaryRoundMatcher;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchShadowGlobalCoordinator;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use OpenPairing\Format\Trf\Trf2026Codec;

$projectRoot = dirname(__DIR__);
$loader = require $projectRoot.'/vendor/autoload.php';
require __DIR__.'/TrfCorpusCompatibility.php';

try {
    $runtimeIdentity = RuntimeCodeIdentity::capture($projectRoot, $loader, DutchPairingEngine::class, DutchPairingEngine::VERSION);
    RuntimeCodeIdentity::assertMatchesLock($runtimeIdentity, $projectRoot.'/runtime-identity.lock.json');
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'code' => 'runtime.identity_mismatch',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(4);
}

$directory = $argv[1] ?? '';
$limit = isset($argv[2]) ? (int) $argv[2] : 0;
$mode = $argv[3] ?? '';
$auditShadowGlobal = $mode === '--shadow-global';
$auditPreliminaryGlobal = $mode === '--preliminary-global' || $auditShadowGlobal;
if ($directory === '' || !is_dir($directory) || $limit < 0 || ($mode !== '' && !$auditPreliminaryGlobal)) {
    fwrite(STDERR, "Usage: verify_trf_pairings.php DIRECTORY [MAX_FILES] [--preliminary-global|--shadow-global]\n");
    exit(2);
}
$root = realpath($directory);
if ($root === false) {
    exit(2);
}
try {
    $corpus = CorpusIdentity::capture($root, $limit);
    $corpusIdentity = $corpus['identity'];
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'code' => 'corpus.identity_error',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(5);
}

$codec = new Trf2026Codec();
$rules = new DutchRules();
$preliminaryMatcher = $auditPreliminaryGlobal ? new DutchPreliminaryRoundMatcher() : null;
$shadowCoordinator = $auditShadowGlobal ? new DutchShadowGlobalCoordinator() : null;
$counts = [
    'filesSeen' => 0,
    'decoded' => 0,
    'tournamentsVerified' => 0,
    'tournamentsAllExact' => 0,
    'roundsAttempted' => 0,
    'roundsExact' => 0,
    'roundsSamePairsColourVariant' => 0,
    'roundsPairingDeviation' => 0,
    'roundsEngineNoSolution' => 0,
    'roundsEngineResourceLimit' => 0,
];
if ($auditPreliminaryGlobal) {
    $counts += [
        'preliminaryRoundsComplete' => 0,
        'preliminaryNoMatching' => 0,
        'preliminaryByeExact' => 0,
        'preliminaryByeScoreExact' => 0,
        'preliminaryByeDifferentWithinScoreGroup' => 0,
        'preliminaryByeWrongScoreGroup' => 0,
    ];
}
if ($auditShadowGlobal) {
    foreach (\OpenPairing\Diagnostics\ShadowPairingCategory::cases() as $category) {
        $counts['shadow.'.str_replace('-', '_', $category->value)] = 0;
    }
    $counts['shadow.noMatching'] = 0;
}
$diagnostics = [];
$samples = [];
$preliminarySamples = [];
$shadowSamples = [];
$shadowTimingSamples = [];
$shadowTimingAggregates = [];
$started = hrtime(true);

foreach ($corpus['files'] as $corpusFile) {
    ++$counts['filesSeen'];
    $relative = $corpusFile['relative'];
    try {
        $raw = file_get_contents($corpusFile['absolute'], false, null, 0, Trf2026Codec::MAX_BYTES + 1);
        if (!is_string($raw)) {
            throw new RuntimeException('Could not read file.');
        }
        CorpusIdentity::assertRawFileMatches($corpusFile, $raw);
        [$normalized] = TrfCorpusCompatibility::normalize($raw);
        $full = $codec->decode($normalized);
        ++$counts['decoded'];
        $scoresEntering = scoresEnteringRounds($full, $rules);
        $allExact = true;
        $roundsVerified = 0;

        for ($round = 1; $round <= $full->numberOfRounds; ++$round) {
            $reference = rawRoundReference($raw, $round);
            if ($reference['pairs'] === [] && $reference['bye'] === null) {
                continue;
            }
            ++$counts['roundsAttempted'];
            ++$roundsVerified;
            $state = historicalState($full, $round, $scoresEntering, $rules);
            $shadow = null;
            if ($shadowCoordinator !== null) {
                try {
                    $shadow = $shadowCoordinator->match($state);
                } catch (Throwable $exception) {
                    ++$counts['shadow.noMatching'];
                    sample($shadowSamples, $relative, $round, 'shadow.infrastructure-error', $exception->getMessage());
                }
            }
            if ($shadow !== null) {
                foreach ($shadow->timings as $timing) {
                    $key = $timing->phase.':'.$timing->scoreLevel;
                    $aggregate = $shadowTimingAggregates[$key] ?? [
                        'phase' => $timing->phase,
                        'scoreLevel' => $timing->scoreLevel,
                        'calls' => 0,
                        'totalMilliseconds' => 0.0,
                        'maximumMilliseconds' => 0.0,
                        'matchingOperations' => 0,
                    ];
                    ++$aggregate['calls'];
                    $aggregate['totalMilliseconds'] += $timing->milliseconds;
                    $aggregate['maximumMilliseconds'] = max($aggregate['maximumMilliseconds'], $timing->milliseconds);
                    $aggregate['matchingOperations'] += $timing->matchingOperations;
                    $shadowTimingAggregates[$key] = $aggregate;
                    if (count($shadowTimingSamples) < 1_000) {
                        $shadowTimingSamples[] = [
                            'file' => $relative,
                            'round' => $round,
                            'phase' => $timing->phase,
                            'scoreLevel' => $timing->scoreLevel,
                            'milliseconds' => round($timing->milliseconds, 6),
                            'matchingOperations' => $timing->matchingOperations,
                            'remainingVertices' => $timing->remainingVertices,
                        ];
                    }
                }
            }
            if ($preliminaryMatcher !== null) {
                $preliminary = $preliminaryMatcher->match($state);
                if ($preliminary->status !== WeightedMatchingStatus::Optimal) {
                    ++$counts['preliminaryNoMatching'];
                    sample($preliminarySamples, $relative, $round, 'preliminary.no_matching', 'The whole-round preliminary graph was not completable.');
                } else {
                    ++$counts['preliminaryRoundsComplete'];
                    if ($reference['bye'] !== null) {
                        $preliminaryBye = $preliminary->unmatchedPlayerIds[0] ?? null;
                        if ($preliminaryBye === $reference['bye']) {
                            ++$counts['preliminaryByeExact'];
                            ++$counts['preliminaryByeScoreExact'];
                        } else {
                            $referencePlayer = null;
                            foreach ($state->players as $player) {
                                if ($player->id === $reference['bye']) {
                                    $referencePlayer = $player;
                                    break;
                                }
                            }
                            $sameScore = $referencePlayer !== null
                                && $preliminary->byeScore !== null
                                && $referencePlayer->pairingScore()->compare($preliminary->byeScore) === 0;
                            $bucket = $sameScore ? 'preliminaryByeDifferentWithinScoreGroup' : 'preliminaryByeWrongScoreGroup';
                            ++$counts[$bucket];
                            if ($sameScore) {
                                ++$counts['preliminaryByeScoreExact'];
                            }
                            sample(
                                $preliminarySamples,
                                $relative,
                                $round,
                                $sameScore ? 'preliminary.bye_same_score' : 'preliminary.bye_wrong_score',
                                sprintf('Reference bye: %d; preliminary bye: %s.', $reference['bye'], $preliminaryBye === null ? 'none' : (string) $preliminaryBye),
                                [
                                    'referenceBye' => $reference['bye'],
                                    'preliminaryBye' => $preliminaryBye,
                                    'candidate' => $sameScore && $preliminaryBye !== null
                                        ? counterfactualByeCandidate($reference, $reference['bye'], $preliminaryBye)
                                        : null,
                                ],
                            );
                        }
                    }
                }
            }
            $result = (new DutchPairingEngine())->pair($state);
            if ($result->violations !== []) {
                $code = $result->violations[0]->code;
                $bucket = $code === 'engine.resource_limit' ? 'roundsEngineResourceLimit' : 'roundsEngineNoSolution';
                ++$counts[$bucket];
                $allExact = false;
                sample($samples, $relative, $round, $code, $result->violations[0]->message);
                continue;
            }

            $generated = generatedRound($result);
            if ($shadow !== null) {
                if ($shadow->status !== WeightedMatchingStatus::Optimal) {
                    ++$counts['shadow.noMatching'];
                    sample($shadowSamples, $relative, $round, 'shadow.no-matching', 'Persistent coordinator did not complete the round.', [
                        'unmatchedPlayerIds' => $shadow->unmatchedPlayerIds,
                        'timings' => array_map(static fn ($timing): array => [
                            'scoreLevel' => $timing->scoreLevel,
                            'phase' => $timing->phase,
                            'remainingVertices' => $timing->remainingVertices,
                        ], $shadow->timings),
                        'freezeEvents' => array_map(static fn ($event): array => [
                            'sequence' => $event->sequence,
                            'bracketIndex' => $event->bracketIndex,
                            'scoreLevel' => $event->scoreLevel,
                            'left' => $event->left,
                            'right' => $event->right,
                            'remainingVerticesBefore' => $event->remainingVerticesBefore,
                            'remainingVerticesAfter' => $event->remainingVerticesAfter,
                        ], $shadow->freezeEvents),
                    ]);
                } else {
                    $shadowGenerated = shadowRound($state, $shadow);
                    $category = ShadowPairingComparison::classify($reference, $generated, $shadowGenerated);
                    ++$counts['shadow.'.str_replace('-', '_', $category->value)];
                    if (ShadowOraclePolicy::requiresGacrux($category) || $category->value === 'candidate-regression') {
                        sample($shadowSamples, $relative, $round, 'shadow.'.$category->value, 'Internal comparison requires external classification.', [
                            'candidate' => pairingCandidate($shadowGenerated),
                            'legacy' => pairingCandidate($generated),
                            'reference' => pairingCandidate($reference),
                            'freezeEvents' => array_map(static fn ($event): array => [
                                'sequence' => $event->sequence,
                                'bracketIndex' => $event->bracketIndex,
                                'scoreLevel' => $event->scoreLevel,
                                'left' => $event->left,
                                'right' => $event->right,
                                'remainingVerticesBefore' => $event->remainingVerticesBefore,
                                'remainingVerticesAfter' => $event->remainingVerticesAfter,
                            ], $shadow->freezeEvents),
                            'firstNonReferenceFreeze' => firstNonReferenceFreeze($shadow, $reference),
                        ]);
                    }
                }
            }
            if ($generated['pairs'] === $reference['pairs'] && $generated['bye'] === $reference['bye']) {
                ++$counts['roundsExact'];
            } elseif (array_keys($generated['pairs']) === array_keys($reference['pairs']) && $generated['bye'] === $reference['bye']) {
                ++$counts['roundsSamePairsColourVariant'];
                $allExact = false;
                sample($samples, $relative, $round, 'same-pairs-colour-variant', 'Same opponents and bye, different colour allocation.');
            } else {
                ++$counts['roundsPairingDeviation'];
                $allExact = false;
                sample($samples, $relative, $round, 'pairing-deviation', sprintf(
                    'Reference pairs: %s, bye: %s; generated pairs: %s, bye: %s.',
                    implode(',', array_keys($reference['pairs'])),
                    $reference['bye'] === null ? 'none' : (string) $reference['bye'],
                    implode(',', array_keys($generated['pairs'])),
                    $generated['bye'] === null ? 'none' : (string) $generated['bye'],
                ), [
                    'candidate' => [
                        'pairs' => array_map(
                            static fn ($table): array => ['white' => $table->whitePlayerId, 'black' => $table->blackPlayerId],
                            $result->tables,
                        ),
                        'bye' => $result->byePlayerId,
                    ],
                ]);
            }
        }

        if ($roundsVerified > 0) {
            ++$counts['tournamentsVerified'];
            if ($allExact) {
                ++$counts['tournamentsAllExact'];
            }
        }
    } catch (RuntimeException $exception) {
        if (str_starts_with($exception->getMessage(), 'Corpus file changed during replay:')) {
            fwrite(STDERR, json_encode([
                'code' => 'corpus.identity_mismatch',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
            exit(5);
        }
        $diagnostics['infrastructure.error'] = ($diagnostics['infrastructure.error'] ?? 0) + 1;
        sample($samples, $relative, 0, 'infrastructure.error', $exception->getMessage());
    } catch (InvalidTournamentState $exception) {
        $diagnostics[$exception->diagnosticCode] = ($diagnostics[$exception->diagnosticCode] ?? 0) + 1;
        sample($samples, $relative, 0, $exception->diagnosticCode, $exception->getMessage());
    } catch (Throwable $exception) {
        $diagnostics['infrastructure.error'] = ($diagnostics['infrastructure.error'] ?? 0) + 1;
        sample($samples, $relative, 0, 'infrastructure.error', $exception->getMessage());
    }
}

arsort($diagnostics);
try {
    $endingRuntimeIdentity = RuntimeCodeIdentity::capture($projectRoot, $loader, DutchPairingEngine::class, DutchPairingEngine::VERSION);
    RuntimeCodeIdentity::assertMatchesLock($endingRuntimeIdentity, $projectRoot.'/runtime-identity.lock.json');
    if ($endingRuntimeIdentity['sourceTreeSha256'] !== $runtimeIdentity['sourceTreeSha256']) {
        throw new RuntimeException('Runtime source tree changed during historical replay.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'code' => 'runtime.identity_mismatch',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(4);
}
echo json_encode([
    'schema' => 'openpairing.trf-historical-pairing-verification/3',
    'historicalFloatSemantics' => DutchFloatClassifier::VERSION,
    'root' => $root,
    'limit' => $limit,
    'preliminaryGlobalAudit' => $auditPreliminaryGlobal,
    'shadowGlobalAudit' => $auditShadowGlobal,
    'elapsedSeconds' => round((hrtime(true) - $started) / 1_000_000_000, 3),
    'runtimeIdentity' => $runtimeIdentity,
    'corpusIdentity' => $corpusIdentity,
    'counts' => $counts,
    'diagnostics' => $diagnostics,
    'samples' => $samples,
    'preliminarySamples' => $preliminarySamples,
    'shadowSamples' => $shadowSamples,
    'shadowTimingSamples' => $shadowTimingSamples,
    'shadowTimingAggregates' => array_values($shadowTimingAggregates),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

/** @return array<int, array<int, Score>> */
function scoresEnteringRounds(TournamentState $state, DutchRules $rules): array
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
                $current = $current->add($rules->pointsForResult($state, $byRound[$round]['result']));
            }
        }
    }

    return $scores;
}

/** @param array<int, array<int, Score>> $scoresEntering */
function historicalState(TournamentState $full, int $round, array $scoresEntering, DutchRules $rules): TournamentState
{
    $floatClassifier = new DutchFloatClassifier($rules);
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
            $float = 'none';
            if ($result['opponentId'] !== null) {
                $opponentScore = $scoresEntering[$result['opponentId']][$result['round']];
            } else {
                $opponentScore = null;
            }
            $float = $floatClassifier->direction(
                $full,
                $scoresEntering[$player->id][$result['round']],
                $opponentScore,
                (string) $result['result'],
                (bool) $result['played'],
            );
            $floats[] = $float;
            $receivedPab = $receivedPab || strtoupper($result['result']) === 'U';
        }
        $active = $current !== null && ($current['opponentId'] !== null || strtoupper($current['result']) === 'U');
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
            acceleration: $player->acceleration,
            sex: $player->sex,
            federation: $player->federation,
            fideId: $player->fideId,
            birthDate: $player->birthDate,
            rank: $player->rank,
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
function rawRoundReference(string $raw, int $round): array
{
    $pairs = [];
    $bye = null;
    $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
    foreach ($lines as $line) {
        if (!str_starts_with($line, '001') || strlen($line) < 89) {
            continue;
        }
        $player = (int) trim(substr($line, 4, 4));
        $base = 89 + (($round - 1) * 10);
        $block = substr($line, $base, 10);
        if (strlen($block) < 10 || trim($block) === '') {
            continue;
        }
        $opponent = (int) trim(substr($block, 2, 4));
        $colour = strtolower(substr($block, 7, 1));
        $result = strtoupper(substr($block, 9, 1));
        if ($result === 'U') {
            $bye = $player;
        }
        if ($opponent < 1 || $player > $opponent) {
            continue;
        }
        $key = $player.'-'.$opponent;
        $pairs[$key] = match ($colour) {
            'w' => $player.'>'.$opponent,
            'b' => $opponent.'>'.$player,
            default => $key,
        };
    }
    ksort($pairs, SORT_NATURAL);

    return ['pairs' => $pairs, 'bye' => $bye];
}

/** @return array{pairs: array<string, string>, bye: int|null} */
function generatedRound(OpenPairing\Domain\Pairing\PairingResult $result): array
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

/** @return array{pairs: array<string, string>, bye: int|null} */
function shadowRound(TournamentState $state, OpenPairing\Engine\Dutch\GlobalMatching\DutchGlobalRoundResult $result): array
{
    if ($state->round === 1) {
        $initial = generatedRound((new OpenPairing\Engine\Dutch\InitialDutchPairingEngine())->pair($state));
        $shadowKeys = array_map(
            static fn (array $pair): string => min($pair['left'], $pair['right']).'-'.max($pair['left'], $pair['right']),
            $result->pairs,
        );
        sort($shadowKeys, SORT_NATURAL);
        if ($shadowKeys === array_keys($initial['pairs']) && $result->byePlayerId === $initial['bye']) {
            return $initial;
        }
    }
    $players = [];
    foreach ($state->players as $player) {
        $players[$player->id] = $player;
    }
    $allocator = new OpenPairing\Engine\Dutch\ColourAllocator();
    $pairs = [];
    foreach ($result->pairs as $pair) {
        $allocation = $allocator->allocate($state, $players[$pair['left']], $players[$pair['right']]);
        $low = min($pair['left'], $pair['right']);
        $high = max($pair['left'], $pair['right']);
        $pairs[$low.'-'.$high] = $allocation->white->id.'>'.$allocation->black->id;
    }
    ksort($pairs, SORT_NATURAL);

    return ['pairs' => $pairs, 'bye' => $result->byePlayerId];
}

/**
 * @param array{pairs: array<string, string>, bye: int|null} $reference
 * @return array<string, int>|null
 */
function firstNonReferenceFreeze(
    OpenPairing\Engine\Dutch\GlobalMatching\DutchGlobalRoundResult $result,
    array $reference,
): ?array {
    foreach ($result->freezeEvents as $event) {
        $isReferenceDecision = $event->left === 0
            ? $reference['bye'] === $event->right
            : array_key_exists(min($event->left, $event->right).'-'.max($event->left, $event->right), $reference['pairs']);
        if (!$isReferenceDecision) {
            return [
                'sequence' => $event->sequence,
                'bracketIndex' => $event->bracketIndex,
                'scoreLevel' => $event->scoreLevel,
                'left' => $event->left,
                'right' => $event->right,
            ];
        }
    }

    return null;
}

/**
 * @param array{pairs: array<string, string>, bye: int|null} $signature
 * @return array{pairs: list<array{white: int, black: int}>, bye: int|null}
 */
function pairingCandidate(array $signature): array
{
    $pairs = [];
    foreach ($signature['pairs'] as $colouredPair) {
        [$white, $black] = array_map('intval', explode('>', $colouredPair));
        $pairs[] = compact('white', 'black');
    }

    return ['pairs' => $pairs, 'bye' => $signature['bye']];
}

/**
 * Preserve every reference board except the board containing the proposed PAB:
 * the reference PAB occupies that player's colour slot, and the proposed PAB
 * becomes unmatched. This isolates the within-score-group PAB choice.
 *
 * @param array{pairs: array<string, string>, bye: int|null} $reference
 * @return array{pairs: list<array{white: int, black: int}>, bye: int}
 */
function counterfactualByeCandidate(array $reference, int $referenceBye, int $proposedBye): array
{
    $pairs = [];
    $replaced = false;
    foreach ($reference['pairs'] as $colouredPair) {
        [$white, $black] = array_map('intval', explode('>', $colouredPair));
        if ($white === $proposedBye) {
            $white = $referenceBye;
            $replaced = true;
        } elseif ($black === $proposedBye) {
            $black = $referenceBye;
            $replaced = true;
        }
        $pairs[] = compact('white', 'black');
    }
    if (!$replaced) {
        throw new RuntimeException('Could not construct the PAB counterfactual: proposed PAB has no reference board.');
    }

    return ['pairs' => $pairs, 'bye' => $proposedBye];
}

/**
 * @param list<array<string, mixed>> $samples
 * @param array<string, mixed> $context
 */
function sample(array &$samples, string $file, int $round, string $code, string $message, array $context = []): void
{
    if (count($samples) < 100) {
        $samples[] = [...compact('file', 'round', 'code', 'message'), ...$context];
    }
}
