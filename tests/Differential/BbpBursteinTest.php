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
    public function testPostSeedingSyntheticCaseHasBbpOpponentParityAndNormativeColours(): void
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
        $opponents = static function (array $pairs): array {
            $normalized = array_map(static function (array $pair): array {
                $ids = [$pair['whitePlayerId'], $pair['blackPlayerId']];
                sort($ids, SORT_NUMERIC);

                return $ids;
            }, $pairs);
            sort($normalized);

            return $normalized;
        };
        $phpPairs = array_map(
            static fn ($table): array => [
                'whitePlayerId' => $table->whitePlayerId,
                'blackPlayerId' => $table->blackPlayerId,
            ],
            $php->tables,
        );
        self::assertSame($opponents($phpPairs), $opponents($oracle->pairs));

        // Both TPN 5 and 7 prefer Black, and TPN 5 is higher by Article 1.8.
        // FIDE Burstein 5.2.5 therefore grants Black to TPN 5. The pinned BBP
        // build reverses this one colour allocation; its own documentation
        // warns that the Burstein implementation may contain imperfections.
        self::assertContains(['whitePlayerId' => 7, 'blackPlayerId' => 5], $phpPairs);
        self::assertContains(['whitePlayerId' => 5, 'blackPlayerId' => 7], $oracle->pairs);
    }
}
