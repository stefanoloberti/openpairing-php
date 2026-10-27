#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Format\Trf\Trf2026Codec;
use OpenPairing\Simulation\RandomTournamentGenerator;
use OpenPairing\Version;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $arguments = array_slice($argv, 1);
    if ($arguments === ['--help'] || $arguments === ['-h']) {
        fwrite(STDOUT, "Usage: openpairing-rtg.php --players=N --rounds=N --seed=N [--system=dutch|burstein] [--top-colour=W|B] [--draw-permille=0..1000] [--baku]\n");
        exit(0);
    }
    if ($arguments === ['--version']) {
        fwrite(STDOUT, json_encode([
            'package' => 'openpairing/openpairing-php',
            'version' => Version::PACKAGE,
            'generatorSchema' => 'openpairing.rtg/1',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(0);
    }
    $options = [];
    foreach ($arguments as $argument) {
        if ($argument === '--baku') {
            if (isset($options['baku'])) {
                throw new InvalidTournamentState('rtg.arguments', '--baku may be supplied only once.');
            }
            $options['baku'] = true;
            continue;
        }
        if (preg_match('/^--(players|rounds|seed|system|top-colour|draw-permille)=(.*)$/D', $argument, $parts) !== 1) {
            throw new InvalidTournamentState('rtg.arguments', sprintf('Unknown RTG argument: %s', $argument));
        }
        if (array_key_exists($parts[1], $options)) {
            throw new InvalidTournamentState('rtg.arguments', sprintf('--%s may be supplied only once.', $parts[1]));
        }
        $options[$parts[1]] = $parts[2];
    }
    foreach (['players', 'rounds', 'seed'] as $required) {
        if (!isset($options[$required]) || !is_string($options[$required]) || preg_match('/^\d+$/D', $options[$required]) !== 1) {
            throw new InvalidTournamentState('rtg.arguments', sprintf('--%s is required and must contain decimal digits.', $required));
        }
    }
    $systemOption = $options['system'] ?? 'dutch';
    if (!is_string($systemOption)) {
        throw new InvalidTournamentState('rtg.arguments', '--system must have one value.');
    }
    $system = match (strtolower($systemOption)) {
        'dutch' => 'fide-dutch-2026',
        'burstein' => 'fide-burstein-2026',
        default => throw new InvalidTournamentState('rtg.arguments', '--system must be dutch or burstein.'),
    };
    $topOption = $options['top-colour'] ?? 'W';
    if (!is_string($topOption) || !in_array(strtoupper($topOption), ['W', 'B'], true)) {
        throw new InvalidTournamentState('rtg.arguments', '--top-colour must be W or B.');
    }
    $drawOption = $options['draw-permille'] ?? '300';
    if (!is_string($drawOption) || preg_match('/^\d+$/D', $drawOption) !== 1) {
        throw new InvalidTournamentState('rtg.arguments', '--draw-permille must contain decimal digits.');
    }
    $state = (new RandomTournamentGenerator())->generate(
        playerCount: (int) $options['players'],
        rounds: (int) $options['rounds'],
        seed: (int) $options['seed'],
        pairingSystem: $system,
        topColour: strtoupper($topOption) === 'W' ? Colour::White : Colour::Black,
        drawPermille: (int) $drawOption,
        baku: ($options['baku'] ?? false) === true,
    );
    fwrite(STDOUT, (new Trf2026Codec())->encode($state));
    exit(0);
} catch (InvalidTournamentState $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'code' => $exception->diagnosticCode,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(3);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'code' => 'infrastructure.error',
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}
