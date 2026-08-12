<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use Composer\Autoload\ClassLoader;
use OpenPairing\Diagnostics\RuntimeCodeIdentity;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use PHPUnit\Framework\TestCase;

final class RuntimeCodeIdentityTest extends TestCase
{
    public function testCapturesTheCheckoutActuallyLoadedByComposer(): void
    {
        $root = dirname(__DIR__, 2);
        $loader = require $root.'/vendor/autoload.php';
        self::assertIsObject($loader);

        $identity = RuntimeCodeIdentity::capture($root, $loader, DutchPairingEngine::class, DutchPairingEngine::VERSION);

        self::assertSame($identity['expectedEnginePath'], $identity['loadedEnginePath']);
        self::assertSame(64, strlen($identity['engineSha256']));
        self::assertSame(64, strlen($identity['sourceTreeSha256']));
        self::assertGreaterThan(50, $identity['sourceFileCount']);
    }

    public function testRejectsAComposerLoaderPointingAtAnotherCheckout(): void
    {
        $loader = new ClassLoader();
        $loader->addPsr4('OpenPairing\\', dirname(__DIR__, 2).'/tests');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stale autoload detected');
        RuntimeCodeIdentity::capture(dirname(__DIR__, 2), $loader, DutchPairingEngine::class, DutchPairingEngine::VERSION);
    }

    public function testRejectsAChangedSourceTreeAgainstTheWorkspaceLock(): void
    {
        $lock = tempnam(sys_get_temp_dir(), 'openpairing-identity-');
        self::assertIsString($lock);
        file_put_contents($lock, json_encode([
            'engineVersion' => 'old',
            'engineSha256' => str_repeat('0', 64),
            'sourceTreeSha256' => str_repeat('0', 64),
            'sourceFileCount' => 0,
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Runtime identity lock mismatch');
            RuntimeCodeIdentity::assertMatchesLock([
                'engineVersion' => DutchPairingEngine::VERSION,
                'engineSha256' => str_repeat('1', 64),
                'sourceTreeSha256' => str_repeat('1', 64),
                'sourceFileCount' => 1,
            ], $lock);
        } finally {
            unlink($lock);
        }
    }
}
