#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Acceleration\BakuAcceleration;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\TieBreak\FideTieBreakRanker;
use OpenPairing\Domain\TieBreak\TieBreakDescriptorParser;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Format\Json\CanonicalJson;
use OpenPairing\Format\Trf\Trf2026Codec;
use OpenPairing\Validation\PairingResultValidator;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $inputPath = '-';
    $inputFormat = 'auto';
    $baku = false;
    $bakuGaLastPlayerId = null;
    $mode = 'pairing';
    $tieBreaks = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            fwrite(STDOUT, "Usage: openpairing.php [--mode=pairing|standings|--standings] [--tie-breaks=LIST] [--input-format=json|trf|auto] [--baku [--baku-ga-last-id=ID]] [FILE|-]\n");
            exit(0);
        }
        if ($argument === '--standings') {
            $mode = 'standings';
            continue;
        }
        if (str_starts_with($argument, '--mode=')) {
            $mode = substr($argument, strlen('--mode='));
            continue;
        }
        if (str_starts_with($argument, '--tie-breaks=')) {
            $tieBreaks = substr($argument, strlen('--tie-breaks='));
            continue;
        }
        if ($argument === '--baku') {
            $baku = true;
            continue;
        }
        if (str_starts_with($argument, '--baku-ga-last-id=')) {
            $value = substr($argument, strlen('--baku-ga-last-id='));
            if (preg_match('/^[1-9]\d*$/D', $value) !== 1) {
                throw new InvalidTournamentState('cli.baku_ga_last', 'Baku GA-last player ID must be a positive integer.');
            }
            $bakuGaLastPlayerId = (int) $value;
            continue;
        }
        if (str_starts_with($argument, '--input-format=')) {
            $inputFormat = substr($argument, strlen('--input-format='));
            continue;
        }
        if ($inputPath !== '-') {
            throw new InvalidTournamentState('cli.arguments', 'Only one input path is accepted.');
        }
        $inputPath = $argument;
    }
    if (!in_array($inputFormat, ['auto', 'json', 'trf'], true)) {
        throw new InvalidTournamentState('cli.input_format', 'Input format must be auto, json or trf.');
    }
    if (!in_array($mode, ['pairing', 'standings'], true)) {
        throw new InvalidTournamentState('cli.mode', 'Mode must be pairing or standings.');
    }
    if ($tieBreaks !== null && $mode !== 'standings') {
        throw new InvalidTournamentState('cli.tie_breaks', '--tie-breaks requires standings mode.');
    }
    if ($bakuGaLastPlayerId !== null && !$baku) {
        throw new InvalidTournamentState('cli.baku_ga_last', '--baku-ga-last-id requires --baku.');
    }
    if ($inputFormat === 'auto') {
        $inputFormat = $inputPath !== '-' && strtolower(pathinfo($inputPath, PATHINFO_EXTENSION)) === 'trf' ? 'trf' : 'json';
    }
    $limit = $inputFormat === 'trf' ? Trf2026Codec::MAX_BYTES : CanonicalJson::MAX_BYTES;
    $stream = $inputPath === '-' ? STDIN : @fopen($inputPath, 'rb');
    if (!is_resource($stream)) {
        throw new InvalidTournamentState('input.read', 'Could not open input.');
    }
    $input = stream_get_contents($stream, $limit + 1);
    if ($inputPath !== '-') {
        fclose($stream);
    }
    if (!is_string($input)) {
        throw new InvalidTournamentState('input.read', 'Could not read input.');
    }
    if (strlen($input) > $limit) {
        throw new InvalidTournamentState('input.too_large', sprintf('Input exceeds the %d-byte CLI limit.', $limit));
    }
    $state = $inputFormat === 'trf'
        ? (new Trf2026Codec())->decode($input)
        : (new CanonicalJson())->decode($input);
    if ($baku) {
        $state = (new BakuAcceleration())->apply($state, $bakuGaLastPlayerId);
    }
    if ($mode === 'standings') {
        $ranker = new FideTieBreakRanker();
        if ($tieBreaks === null) {
            $ranking = $ranker->rankFromTournamentRecord($state);
            $rankOrder = $state->tieBreaks;
        } else {
            try {
                $definitions = (new TieBreakDescriptorParser())->parseList($tieBreaks);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidTournamentState('tiebreak.descriptor_invalid', $exception->getMessage());
            }
            $ranking = $ranker->rank($state, $definitions);
            $rankOrder = 'PTS'.($tieBreaks === '' ? '' : ','.$tieBreaks);
        }
        echo json_encode([
            'status' => 'ok',
            'mode' => 'standings',
            'rankOrder' => $rankOrder,
            'ranking' => $ranking->toArray(),
            'standings' => $ranking->standings($state),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    }
    $engine = match ($state->pairingSystem) {
        'fide-dutch-2026' => new DutchPairingEngine(),
        'fide-burstein-2026' => new BursteinPairingEngine(),
        default => throw new InvalidTournamentState('pairing_system.unsupported', 'The pairing system is not supported by the CLI.'),
    };
    $result = $engine->pair($state);
    $violations = (new PairingResultValidator())->validate($state, $result);
    $output = $result->toArray();
    $output['validationViolations'] = array_map(static fn ($violation): array => $violation->toArray(), $violations);
    echo json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($violations === [] && $result->violations === [] ? 0 : 1);
} catch (InvalidTournamentState $exception) {
    $message = $exception->getMessage();
    if ($exception->diagnosticCode === 'trf.record_required' && str_contains($message, '192 record')) {
        $message .= ' Legacy TRFs must declare the pairing system explicitly, for example: 192 FIDE_DUTCH_2025.';
    }
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'code' => $exception->diagnosticCode,
        'message' => $message,
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
