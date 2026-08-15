#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Application\PairingEngineInterface;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Engine\Matching\DynamicUInt;
use OpenPairing\Tests\Fixtures\DeterministicTournamentSimulator;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $options = parseOptions(array_slice($argv, 1));
    $players = decimalOption($options, 'players', 2, 4_096);
    $round = decimalOption($options, 'round', 1, 256);
    $rounds = isset($options['rounds']) ? decimalOption($options, 'rounds', $round, 256) : max(9, $round);
    $repetitions = isset($options['repetitions']) ? decimalOption($options, 'repetitions', 1, 20) : 3;
    if ($rounds >= $players) {
        throw new InvalidArgumentException('--rounds must be smaller than --players.');
    }
    $systemOption = $options['system'] ?? 'dutch';
    $system = match ($systemOption) {
        'dutch' => 'fide-dutch-2026',
        'burstein' => 'fide-burstein-2026',
        default => throw new InvalidArgumentException('--system must be dutch or burstein.'),
    };
    $engine = $system === 'fide-dutch-2026' ? new DutchPairingEngine() : new BursteinPairingEngine();

    $preparationStarted = hrtime(true);
    $state = DeterministicTournamentSimulator::initial($players, $rounds, $system);
    while ($state->round < $round) {
        $result = $engine->pair($state);
        assertComplete($engine, $state->activePlayers(), $result->toArray(), $state->round);
        $state = DeterministicTournamentSimulator::advance($state, $result);
    }
    $preparationMilliseconds = (hrtime(true) - $preparationStarted) / 1_000_000;

    $measurements = [];
    $resultHashes = [];
    $peakMemory = 0;
    for ($iteration = 1; $iteration <= $repetitions; ++$iteration) {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $started = hrtime(true);
        $result = $engine->pair($state);
        $milliseconds = (hrtime(true) - $started) / 1_000_000;
        $array = $result->toArray();
        assertComplete($engine, $state->activePlayers(), $array, $round);
        $measurements[] = round($milliseconds, 3);
        $resultHashes[] = functionalResultHash($array);
        $peakMemory = max($peakMemory, memory_get_peak_usage(true));
    }
    if (count(array_unique($resultHashes)) !== 1) {
        throw new RuntimeException('Repeated pairing calls did not return a deterministic result.');
    }
    sort($measurements, SORT_NUMERIC);

    echo json_encode([
        'schema' => 'openpairing.turn-benchmark/1',
        'generatedAtUtc' => gmdate(DATE_ATOM),
        'environment' => [
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY.' '.php_uname('r').' '.php_uname('m'),
            'arithmeticBackend' => DynamicUInt::backendName(),
            'opcacheEnableCli' => (string) ini_get('opcache.enable_cli'),
            'jit' => (string) ini_get('opcache.jit'),
            'jitBufferSize' => (string) ini_get('opcache.jit_buffer_size'),
        ],
        'scenario' => [
            'system' => $system,
            'players' => $players,
            'round' => $round,
            'numberOfRounds' => $rounds,
            'fixture' => 'deterministic-simulator/1',
            'repetitions' => $repetitions,
        ],
        'preparationMilliseconds' => round($preparationMilliseconds, 3),
        'pairingMilliseconds' => [
            'minimum' => $measurements[0],
            'median' => $measurements[intdiv(count($measurements), 2)],
            'maximum' => $measurements[count($measurements) - 1],
            'samples' => $measurements,
        ],
        'peakMemoryBytes' => $peakMemory,
        'functionalResultSha256' => $resultHashes[0],
        'status' => 'valid',
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'code' => $exception instanceof InvalidArgumentException ? 'benchmark.arguments' : 'benchmark.infrastructure',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($exception instanceof InvalidArgumentException ? 3 : 2);
}

/** @param list<string> $arguments @return array<string, string> */
function parseOptions(array $arguments): array
{
    if ($arguments === ['--help'] || $arguments === ['-h']) {
        fwrite(STDOUT, "Usage: benchmark_turn.php --players=N --round=N [--rounds=N] [--system=dutch|burstein] [--repetitions=N]\n");
        exit(0);
    }
    $options = [];
    foreach ($arguments as $argument) {
        if (preg_match('/^--(players|round|rounds|system|repetitions)=(.*)$/D', $argument, $parts) !== 1) {
            throw new InvalidArgumentException(sprintf('Unknown benchmark argument: %s', $argument));
        }
        if (array_key_exists($parts[1], $options)) {
            throw new InvalidArgumentException(sprintf('--%s may be supplied only once.', $parts[1]));
        }
        $options[$parts[1]] = strtolower($parts[2]);
    }
    foreach (['players', 'round'] as $required) {
        if (!isset($options[$required])) {
            throw new InvalidArgumentException(sprintf('--%s is required.', $required));
        }
    }

    return $options;
}

/** @param array<string, string> $options */
function decimalOption(array $options, string $name, int $minimum, int $maximum): int
{
    $raw = $options[$name] ?? '';
    if (preg_match('/^[0-9]+$/D', $raw) !== 1) {
        throw new InvalidArgumentException(sprintf('--%s must contain decimal digits.', $name));
    }
    $value = (int) $raw;
    if ($value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException(sprintf('--%s must be between %d and %d.', $name, $minimum, $maximum));
    }

    return $value;
}

/** @param list<OpenPairing\Domain\Tournament\Player> $active @param array<string, mixed> $result */
function assertComplete(PairingEngineInterface $engine, array $active, array $result, int $round): void
{
    if (($result['violations'] ?? []) !== [] || ($result['unpairedPlayerIds'] ?? []) !== []) {
        throw new RuntimeException(sprintf('%s did not complete round %d.', $engine::class, $round));
    }
    $accounted = 2 * count($result['tables'] ?? []);
    if (($result['byePlayerId'] ?? null) !== null) {
        ++$accounted;
    }
    if ($accounted !== count($active)) {
        throw new RuntimeException(sprintf('Round %d did not account for every active player.', $round));
    }
}

/** @param array<string, mixed> $result */
function functionalResultHash(array $result): string
{
    $violations = [];
    foreach ($result['violations'] ?? [] as $violation) {
        if (!is_array($violation)) {
            throw new RuntimeException('Pairing violation is not a structured object.');
        }
        $violations[] = [
            'code' => $violation['code'] ?? null,
            'severity' => $violation['severity'] ?? null,
            'context' => $violation['context'] ?? null,
        ];
    }
    $functional = [
        'algorithmVersion' => $result['algorithmVersion'] ?? null,
        'tables' => $result['tables'] ?? null,
        'byePlayerId' => $result['byePlayerId'] ?? null,
        'unpairedPlayerIds' => $result['unpairedPlayerIds'] ?? null,
        'violations' => $violations,
        'qualityVector' => $result['qualityVector'] ?? null,
        'floatAssignments' => $result['floatAssignments'] ?? null,
    ];

    return hash('sha256', json_encode($functional, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}
