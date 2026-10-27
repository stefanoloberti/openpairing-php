#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Format\Trf\Trf2026Codec;
use OpenPairing\Verification\TrfPairingChecker;
use OpenPairing\Version;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $arguments = array_slice($argv, 1);
    if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
        fwrite(STDOUT, "Usage: openpairing-ptc.php [--version] TRF_FILE|-\n");
        exit(0);
    }
    if ($arguments === ['--version']) {
        fwrite(STDOUT, json_encode([
            'package' => 'openpairing/openpairing-php',
            'version' => Version::PACKAGE,
            'checkerSchema' => 'openpairing.ptc-report/1',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(0);
    }
    if (count($arguments) !== 1 || ($arguments[0] !== '-' && str_starts_with($arguments[0], '-'))) {
        throw new InvalidTournamentState('ptc.arguments', 'Exactly one TRF file path or - for standard input is required.');
    }
    $path = $arguments[0];
    $stream = $path === '-' ? STDIN : @fopen($path, 'rb');
    if (!is_resource($stream)) {
        throw new InvalidTournamentState('input.read', 'Could not open PTC input.');
    }
    $input = stream_get_contents($stream, Trf2026Codec::MAX_BYTES + 1);
    if ($path !== '-') {
        fclose($stream);
    }
    if (!is_string($input)) {
        throw new InvalidTournamentState('input.read', 'Could not read PTC input.');
    }
    if (strlen($input) > Trf2026Codec::MAX_BYTES) {
        throw new InvalidTournamentState('input.too_large', 'PTC input exceeds the 2 MiB TRF limit.');
    }

    $report = (new TrfPairingChecker())->check($input);
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($report['status'] === 'exact' ? 0 : 1);
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
