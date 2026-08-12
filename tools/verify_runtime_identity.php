#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Diagnostics\RuntimeCodeIdentity;
use OpenPairing\Engine\Dutch\DutchPairingEngine;

$root = dirname(__DIR__);
$loader = require $root.'/vendor/autoload.php';

try {
    $identity = RuntimeCodeIdentity::capture($root, $loader, DutchPairingEngine::class, DutchPairingEngine::VERSION);
    if (getenv('OPENPAIRING_IDENTITY_BOOTSTRAP') !== '1') {
        RuntimeCodeIdentity::assertMatchesLock($identity, $root.'/runtime-identity.lock.json');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'code' => 'runtime.identity_mismatch',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(4);
}

echo json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
