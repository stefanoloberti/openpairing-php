<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Differential;

use OpenPairing\Development\Oracle\BbpOracleAdapter;
use OpenPairing\Development\Oracle\OracleStatus;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Tests\Fixtures\DeterministicTournamentSimulator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('oracle')]
final class BbpBursteinTest extends TestCase
{
    public function testPostSeedingSyntheticCaseHasExactBbpParity(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('This checkout builds the development BBP oracle inside Windows WSL.');
        }
        $state = DeterministicTournamentSimulator::initial(8, 4, 'fide-burstein-2026');
        $engine = new BursteinPairingEngine();
        for ($round = 1; $round <= 2; ++$round) {
            $state = DeterministicTournamentSimulator::advance($state, $engine->pair($state));
        }

        $root = dirname(__DIR__, 2);
        $oracle = (new BbpOracleAdapter($root.'/tools/upstream/bbp-pairings/bbpPairings.exe'))->pair($state);
        self::assertSame(OracleStatus::Valid, $oracle->status, $oracle->stderr);
        $php = $engine->pair($state);

        self::assertSame($php->byePlayerId, $oracle->byePlayerId);
        self::assertSame(
            array_map(
                static fn ($table): array => [
                    'whitePlayerId' => $table->whitePlayerId,
                    'blackPlayerId' => $table->blackPlayerId,
                ],
                $php->tables,
            ),
            $oracle->pairs,
        );
    }
}
