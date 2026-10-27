#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Domain\TieBreak\FideTieBreakRanker;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Format\Trf\Trf2026Codec;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/TrfCorpusCompatibility.php';

$directory = $argv[1] ?? '';
$limit = isset($argv[2]) ? (int) $argv[2] : 0;
$roundTripEvery = isset($argv[3]) ? (int) $argv[3] : 1;
if ($directory === '' || !is_dir($directory) || $limit < 0 || $roundTripEvery < 1) {
    fwrite(STDERR, "Usage: scan_trf_corpus.php DIRECTORY [MAX_FILES] [ROUND_TRIP_EVERY]\n");
    exit(2);
}

$root = realpath($directory);
if ($root === false) {
    fwrite(STDERR, "Could not resolve corpus directory.\n");
    exit(2);
}

$started = hrtime(true);
$codec = new Trf2026Codec();
$ranker = new FideTieBreakRanker();
$counts = [
    'filesSeen' => 0,
    'bytesSeen' => 0,
    'decoded' => 0,
    'strictDecoded' => 0,
    'legacyNormalizedDecoded' => 0,
    'roundTrip' => 0,
    'pairingAttempted' => 0,
    'pairingValid' => 0,
    'pairingNoSolutionOrViolation' => 0,
    'standingsAttempted' => 0,
    'standingsValid' => 0,
];
$diagnostics = [];
$legacyTransformations = [];
$samples = ['decoded' => [], 'pairingValid' => [], 'pairingRejected' => [], 'standingsValid' => [], 'errors' => []];
$systems = [];
$records = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'trf') {
        continue;
    }
    if ($limit > 0 && $counts['filesSeen'] >= $limit) {
        break;
    }
    ++$counts['filesSeen'];
    $counts['bytesSeen'] += $file->getSize();
    $path = $file->getPathname();
    $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
    try {
        $contents = file_get_contents($path, false, null, 0, Trf2026Codec::MAX_BYTES + 1);
        if (!is_string($contents)) {
            throw new RuntimeException('Could not read file.');
        }
        try {
            $state = $codec->decode($contents);
            ++$counts['strictDecoded'];
        } catch (InvalidTournamentState $strictException) {
            [$normalized, $transformations] = TrfCorpusCompatibility::normalize($contents);
            if ($transformations === []) {
                throw $strictException;
            }
            $state = $codec->decode($normalized);
            ++$counts['legacyNormalizedDecoded'];
            foreach ($transformations as $transformation) {
                $legacyTransformations[$transformation] = ($legacyTransformations[$transformation] ?? 0) + 1;
            }
        }
        ++$counts['decoded'];
        $systems[$state->pairingSystem] = ($systems[$state->pairingSystem] ?? 0) + 1;
        $record = $state->tieBreaksRecord === '' ? 'none' : $state->tieBreaksRecord;
        $records[$record] = ($records[$record] ?? 0) + 1;
        selfSample($samples['decoded'], $relative);

        if (($counts['filesSeen'] - 1) % $roundTripEvery === 0) {
            $roundTrip = $codec->decode($codec->encode($state));
            if (count($roundTrip->players) !== count($state->players) || $roundTrip->round !== $state->round) {
                throw new RuntimeException('Round-trip changed player count or current round.');
            }
            ++$counts['roundTrip'];
        }

        if ($state->round <= $state->numberOfRounds && $state->pairingSystem !== 'fide-round-robin') {
            ++$counts['pairingAttempted'];
            $result = match ($state->pairingSystem) {
                'fide-dutch-2026' => (new DutchPairingEngine())->pair($state),
                'fide-burstein-2026' => (new BursteinPairingEngine())->pair($state),
                default => null,
            };
            if ($result !== null && $result->violations === []) {
                ++$counts['pairingValid'];
                selfSample($samples['pairingValid'], $relative);
            } else {
                ++$counts['pairingNoSolutionOrViolation'];
                if (count($samples['pairingRejected']) < 25) {
                    $samples['pairingRejected'][] = [
                        'file' => $relative,
                        'violations' => $result?->violations ?? ['unsupported pairing system'],
                    ];
                }
            }
        }

        if ($state->tieBreaksRecord !== '') {
            ++$counts['standingsAttempted'];
            $ranking = $ranker->rankFromTournamentRecord($state);
            if (count($ranking->standings($state)) !== count($state->players)) {
                throw new RuntimeException('Standings omitted participants.');
            }
            ++$counts['standingsValid'];
            selfSample($samples['standingsValid'], $relative);
        }
    } catch (InvalidTournamentState $exception) {
        recordDiagnostic($diagnostics, $samples['errors'], $exception->diagnosticCode, $exception->getMessage(), $relative);
    } catch (Throwable $exception) {
        recordDiagnostic($diagnostics, $samples['errors'], 'infrastructure.error', $exception->getMessage(), $relative);
    }
}

arsort($diagnostics);
ksort($systems);
ksort($records);
ksort($legacyTransformations);
$report = [
    'schema' => 'openpairing.trf-corpus-scan/1',
    'engineVersion' => OpenPairing\Domain\TieBreak\FideTieBreakCalculator::VERSION,
    'root' => $root,
    'limit' => $limit,
    'roundTripEvery' => $roundTripEvery,
    'elapsedSeconds' => round((hrtime(true) - $started) / 1_000_000_000, 3),
    'counts' => $counts,
    'systems' => $systems,
    'tieBreakRecords' => $records,
    'diagnostics' => $diagnostics,
    'legacyTransformations' => $legacyTransformations,
    'samples' => $samples,
];
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

/** @param list<string> $samples */
function selfSample(array &$samples, string $relative): void
{
    if (count($samples) < 10) {
        $samples[] = $relative;
    }
}

/**
 * @param array<string, int> $diagnostics
 * @param list<array{code: string, message: string, file: string}> $samples
 */
function recordDiagnostic(array &$diagnostics, array &$samples, string $code, string $message, string $relative): void
{
    $diagnostics[$code] = ($diagnostics[$code] ?? 0) + 1;
    if (count($samples) < 25) {
        $samples[] = ['code' => $code, 'message' => $message, 'file' => $relative];
    }
}
