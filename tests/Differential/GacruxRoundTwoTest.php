<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Differential;

use OpenPairing\Development\Oracle\GacruxOracleAdapter;
use OpenPairing\Development\Oracle\OracleStatus;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Format\Json\CanonicalJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('oracle')]
final class GacruxRoundTwoTest extends TestCase
{
    public function testExactRoundTwoParityWithGacrux(): void
    {
        $root = dirname(__DIR__, 2);
        $json = file_get_contents($root.'/tests/Fixtures/round-2-four.json');
        self::assertIsString($json);
        $state = (new CanonicalJson())->decode($json);
        $oracle = (new GacruxOracleAdapter(
            getenv('OPENPAIRING_GACRUX_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'),
            $root.'/tools/upstream/gacrux',
            $root.'/tools/oracle/gacrux_bridge.py',
        ))->pair($state);
        self::assertSame(OracleStatus::Valid, $oracle->status, $oracle->stderr);

        $php = (new DutchPairingEngine())->pair($state);
        self::assertSame($php->byePlayerId, $oracle->byePlayerId);
        self::assertSame(
            array_map(static fn ($table): array => ['whitePlayerId' => $table->whitePlayerId, 'blackPlayerId' => $table->blackPlayerId], $php->tables),
            $oracle->pairs,
        );
    }
}
