#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Development\Oracle\BbpOracleAdapter;
use OpenPairing\Development\Oracle\OracleStatus;
use OpenPairing\Engine\Dutch\DutchFloatClassifier;
use OpenPairing\Engine\Dutch\DutchRules;
use OpenPairing\Format\Trf\Trf2026Codec;

$rootOverride = getenv('OPENPAIRING_PROJECT_ROOT');
$root = is_string($rootOverride) && $rootOverride !== '' ? $rootOverride : dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require $root.'/tools/TrfCorpusCompatibility.php';

[$reportPath, $corpusRoot, $executable, $outputPath] = array_pad(array_slice($argv, 1), 4, null);
$allowEmpty = in_array('--allow-empty', array_slice($argv, 5), true);
if ($reportPath === null || $corpusRoot === null || $executable === null || $outputPath === null) {
    fwrite(STDERR, "Usage: compare_bbp_shadow_residuals.php REPORT CORPUS_ROOT BBP_EXECUTABLE OUTPUT [--allow-empty]\n");
    exit(2);
}
$report = json_decode((string) file_get_contents($reportPath), true, 64, JSON_THROW_ON_ERROR);
$codec = new Trf2026Codec();
$rules = new DutchRules();
$adapter = new BbpOracleAdapter($executable);
$results = [];
$counts = ['bbp-reference-exact' => 0, 'bbp-shadow-exact' => 0, 'bbp-third-result' => 0, 'infrastructure-error' => 0];
foreach ($report['shadowSamples'] ?? [] as $sample) {
    if (!in_array($sample['code'] ?? '', [
        'shadow.candidate-regression', 'shadow.same-deviation', 'shadow.divergent-deviation',
    ], true)) {
        continue;
    }
    $raw = (string) file_get_contents(rtrim($corpusRoot, '/').'/'.$sample['file']);
    [$normalized] = TrfCorpusCompatibility::normalize($raw);
    $full = $codec->decode($normalized);
    $state = historicalState($full, (int) $sample['round'], $rules);
    $bbp = $adapter->pair($state);
    if ($bbp->status !== OracleStatus::Valid) {
        $category = 'infrastructure-error';
    } else {
        $bbpSignature = signature($bbp->pairs, $bbp->byePlayerId, 'whitePlayerId', 'blackPlayerId');
        $referenceSignature = signature($sample['reference']['pairs'], $sample['reference']['bye'], 'white', 'black');
        $shadowSignature = signature($sample['candidate']['pairs'], $sample['candidate']['bye'], 'white', 'black');
        $category = $bbpSignature === $referenceSignature
            ? 'bbp-reference-exact'
            : ($bbpSignature === $shadowSignature ? 'bbp-shadow-exact' : 'bbp-third-result');
    }
    ++$counts[$category];
    $results[] = [
        'file' => $sample['file'],
        'round' => $sample['round'],
        'shadowCode' => $sample['code'],
        'category' => $category,
        'bbpStatus' => $bbp->status->value,
        'bbp' => ['pairs' => $bbp->pairs, 'bye' => $bbp->byePlayerId],
        'reference' => $sample['reference'],
        'shadow' => $sample['candidate'],
        'stderr' => $bbp->stderr,
    ];
}
if ($results === [] && !$allowEmpty) {
    fwrite(STDERR, "Diagnostic selected zero BBP residuals; use --allow-empty only when zero is intentional.\n");
    exit(4);
}
$reportGitCommit = getenv('OPENPAIRING_REPORT_GIT_COMMIT');
if (!is_string($reportGitCommit) || preg_match('/^[0-9a-f]{40}$/D', $reportGitCommit) !== 1) {
    fwrite(STDERR, "OPENPAIRING_REPORT_GIT_COMMIT must identify the committed tree used by the report.\n");
    exit(3);
}
$output = [
    'schema' => 'openpairing.bbp-shadow-residual-comparison/1',
    'sourceGitCommit' => $reportGitCommit,
    'sourceRuntimeIdentity' => $report['runtimeIdentity'] ?? null,
    'sourceCorpusIdentity' => $report['corpusIdentity'] ?? null,
    'sourceHistoricalFloatSemantics' => $report['historicalFloatSemantics'] ?? null,
    'bbp' => [
        'commit' => '8f9e3c5ffdc4d7a08a33d31c6fb49b4e7acef4d5',
        'executableSha256' => hash_file('sha256', $executable),
        'role' => 'consultative; Gacrux corpus remains the acceptance gate',
    ],
    'counts' => $counts,
    'results' => $results,
];
file_put_contents($outputPath, json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode(['counts' => $counts, 'compared' => count($results)], JSON_THROW_ON_ERROR), PHP_EOL;

/** @return array{pairs: list<string>, bye: int|null} */
function signature(array $pairs, ?int $bye, string $whiteKey, string $blackKey): array
{
    $values = [];
    foreach ($pairs as $pair) {
        $values[] = (int) $pair[$whiteKey].'-'.(int) $pair[$blackKey];
    }
    sort($values, SORT_STRING);

    return ['pairs' => $values, 'bye' => $bye];
}

function historicalState(TournamentState $full, int $round, DutchRules $rules): TournamentState
{
    $scores = [];
    foreach ($full->players as $player) {
        $score = Score::zero();
        $byRound = [];
        foreach ($player->results as $result) {
            $byRound[$result['round']] = $result;
        }
        for ($number = 1; $number <= $full->numberOfRounds + 1; ++$number) {
            $scores[$player->id][$number] = $score;
            if (isset($byRound[$number])) {
                $score = $score->add($rules->pointsForResult($full, $byRound[$number]['result']));
            }
        }
    }
    $classifier = new DutchFloatClassifier($rules);
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
            $playerScore = TrfCorpusCompatibility::pairingScoreEnteringRound(
                $player, $scores[$player->id][$historyRound], $historyRound,
            );
            $opponentScore = null;
            if ($result['opponentId'] !== null) {
                $opponentId = $result['opponentId'];
                $opponentScore = TrfCorpusCompatibility::pairingScoreEnteringRound(
                    $playersById[$opponentId], $scores[$opponentId][$historyRound], $historyRound,
                );
            }
            $floats[] = $classifier->direction(
                $full, $playerScore, $opponentScore,
                (string) $result['result'], (bool) $result['played'],
            );
            $receivedPab = $receivedPab || strtoupper((string) $result['result']) === 'U';
        }
        $active = $current !== null && ($current['opponentId'] !== null || strtoupper((string) $current['result']) === 'U');
        $players[] = new Player(
            $player->id, $player->pairingNumber, $player->name, $player->rating,
            $player->title, $player->team, $player->club, $active,
            $scores[$player->id][$round], $opponents, $colours, $previous,
            $receivedPab, $floats, $player->acceleration, $player->sex,
            $player->federation, $player->fideId, $player->birthDate, $player->rank,
            $player->accelerationSchedule,
        );
    }

    return new TournamentState(
        $full->schemaVersion, $round, $full->numberOfRounds, $full->pairingSystem,
        $full->topColour, $full->pointsForWin, $full->pointsForDraw, $full->maxPlayers,
        $players, $full->tournamentName, $full->pointsForLoss,
        $full->pointsForZeroPointBye, $full->pointsForPairingAllocatedBye, $full->pointsForUnknown,
    );
}
