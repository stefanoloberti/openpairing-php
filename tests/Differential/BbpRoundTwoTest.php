<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Differential;

use OpenPairing\Development\Oracle\BbpOracleAdapter;
use OpenPairing\Development\Oracle\OracleStatus;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Format\Json\CanonicalJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('oracle')]
final class BbpRoundTwoTest extends TestCase
{
    public function testExactRoundTwoParityWithBbp(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('This checkout builds the development BBP oracle inside Windows WSL.');
        }
        $root = dirname(__DIR__, 2);
        $json = file_get_contents($root.'/tests/Fixtures/round-2-four.json');
        self::assertIsString($json);
        $state = (new CanonicalJson())->decode($json);
        $oracle = (new BbpOracleAdapter($root.'/tools/upstream/bbp-pairings/bbpPairings.exe'))->pair($state);
        self::assertSame(OracleStatus::Valid, $oracle->status, $oracle->stderr);

        $php = (new DutchPairingEngine())->pair($state);
        self::assertSame($php->byePlayerId, $oracle->byePlayerId);
        self::assertSame(
            array_map(static fn ($table): array => ['whitePlayerId' => $table->whitePlayerId, 'blackPlayerId' => $table->blackPlayerId], $php->tables),
            $oracle->pairs,
        );
    }
}
