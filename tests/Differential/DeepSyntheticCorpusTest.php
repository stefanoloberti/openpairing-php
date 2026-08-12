<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Differential;

use OpenPairing\Development\Oracle\BbpOracleAdapter;
use OpenPairing\Development\Oracle\GacruxOracleAdapter;
use OpenPairing\Development\Oracle\OracleStatus;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Tests\Fixtures\DeterministicTournamentSimulator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('oracle')]
final class DeepSyntheticCorpusTest extends TestCase
{
    public function testThreeRoundExactParityWithBothOracles(): void
    {
        $root = dirname(__DIR__, 2);
        $gacrux = new GacruxOracleAdapter(
            PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3',
            $root.'/tools/upstream/gacrux',
            $root.'/tools/oracle/gacrux_bridge.py',
        );
        $bbp = PHP_OS_FAMILY === 'Windows'
            ? new BbpOracleAdapter($root.'/tools/upstream/bbp-pairings/bbpPairings.exe')
            : null;
        $state = DeterministicTournamentSimulator::initial(8, 5);
        for ($round = 1; $round <= 3; ++$round) {
            $php = (new DutchPairingEngine())->pair($state);
            self::assertSame([], $php->violations, 'PHP round '.$round);
            foreach (array_filter([$gacrux, $bbp]) as $oracle) {
                $oracleResult = $oracle->pair($state);
                self::assertSame(OracleStatus::Valid, $oracleResult->status, $oracleResult->stderr);
                self::assertSame($php->byePlayerId, $oracleResult->byePlayerId, 'Bye round '.$round);
                self::assertSame(
                    array_map(static fn ($table): array => ['whitePlayerId' => $table->whitePlayerId, 'blackPlayerId' => $table->blackPlayerId], $php->tables),
                    $oracleResult->pairs,
                    'Pairing round '.$round,
                );
            }
            $state = DeterministicTournamentSimulator::advance($state, $php);
        }
    }
}
