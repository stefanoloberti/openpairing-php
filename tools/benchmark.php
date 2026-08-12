#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Application\PairingEngineInterface;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Engine\Matching\ExactWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\MatchingLimitExceeded;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Tests\Fixtures\DeterministicTournamentSimulator;

require dirname(__DIR__).'/vendor/autoload.php';

$sizes = [16, 32, 64, 128, 256];
$records = [];
foreach (['fide-dutch-2026', 'fide-burstein-2026'] as $system) {
    foreach ($sizes as $size) {
        $engine = $system === 'fide-dutch-2026' ? new DutchPairingEngine() : new BursteinPairingEngine();
        $records[] = benchmarkPairing($engine, initialState($size, $system), 'pairing-initial-round');
    }
}
foreach (['fide-dutch-2026', 'fide-burstein-2026'] as $system) {
    foreach ([8, 12, 16] as $size) {
        $engine = $system === 'fide-dutch-2026' ? new DutchPairingEngine() : new BursteinPairingEngine();
        $state = DeterministicTournamentSimulator::initial($size, 4, $system);
        for ($round = 1; $round <= 2; ++$round) {
            $state = DeterministicTournamentSimulator::advance($state, $engine->pair($state));
        }
        $records[] = benchmarkPairing($engine, $state, 'pairing-post-seeding-round');
    }
}
foreach ([16, 22, 32, 64, 128, 256] as $size) {
    $records[] = benchmarkMatching($size);
}

echo json_encode([
    'generatedAtUtc' => gmdate(DATE_ATOM),
    'php' => PHP_VERSION,
    'os' => PHP_OS_FAMILY.' '.php_uname('r').' '.php_uname('m'),
    'records' => $records,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

/** @return array<string, bool|float|int|string|null> */
function benchmarkPairing(PairingEngineInterface $engine, TournamentState $state, string $kind): array
{
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $beforeMemory = memory_get_usage(true);
    $started = hrtime(true);
    $result = $engine->pair($state);
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    $context = $result->diagnostics[0]->context ?? [];

    return [
        'kind' => $kind,
        'system' => $state->pairingSystem,
        'players' => count($state->players),
        'status' => $result->violations === [] ? 'valid' : $result->violations[0]->code,
        'milliseconds' => round($elapsed, 3),
        'peakMemoryBytes' => memory_get_peak_usage(true),
        'additionalPeakMemoryBytes' => max(0, memory_get_peak_usage(true) - $beforeMemory),
        'searchNodes' => is_int($context['searchNodes'] ?? null) ? $context['searchNodes'] : null,
        'candidates' => is_int($context['candidates'] ?? null) ? $context['candidates'] : null,
        'backtracks' => is_int($context['backtracks'] ?? null) ? $context['backtracks'] : null,
        'matchingMilliseconds' => null,
    ];
}

/** @return array<string, bool|float|int|string|null> */
function benchmarkMatching(int $size): array
{
    $vertices = range(1, $size);
    $edges = [];
    for ($vertex = 1; $vertex <= $size; $vertex += 2) {
        $edges[] = new WeightedEdge($vertex, $vertex + 1, [1, -$vertex]);
    }
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $beforeMemory = memory_get_usage(true);
    $started = hrtime(true);
    try {
        $result = (new ExactWeightedGeneralMatcher())->match($vertices, $edges, true);
        $status = $result->status->value;
        $states = $result->statesExplored;
    } catch (MatchingLimitExceeded) {
        $status = 'resource_limit';
        $states = 0;
    }
    $elapsed = (hrtime(true) - $started) / 1_000_000;

    return [
        'kind' => 'weighted-general-matching-sparse',
        'system' => 'exact-php-dp',
        'players' => $size,
        'status' => $status,
        'milliseconds' => round($elapsed, 3),
        'peakMemoryBytes' => memory_get_peak_usage(true),
        'additionalPeakMemoryBytes' => max(0, memory_get_peak_usage(true) - $beforeMemory),
        'searchNodes' => $states,
        'candidates' => null,
        'backtracks' => null,
        'matchingMilliseconds' => round($elapsed, 3),
    ];
}

function initialState(int $count, string $system): TournamentState
{
    $players = [];
    for ($tpn = 1; $tpn <= $count; ++$tpn) {
        $players[] = new Player(
            id: $tpn,
            pairingNumber: $tpn,
            name: 'Benchmark Player '.$tpn,
            rating: 2601 - $tpn,
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
        numberOfRounds: 9,
        pairingSystem: $system,
        topColour: Colour::White,
        pointsForWin: new Score(1, 1),
        pointsForDraw: new Score(1, 2),
        maxPlayers: 256,
        players: $players,
        tournamentName: 'OpenPairingPHP reproducible benchmark',
    );
}
