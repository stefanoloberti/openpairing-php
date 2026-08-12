<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Development\Oracle\ComparisonCategory;
use OpenPairing\Development\Oracle\DifferentialClassifier;
use OpenPairing\Development\Oracle\OracleResult;
use OpenPairing\Development\Oracle\OracleStatus;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Pairing\PairingTable;
use OpenPairing\Engine\Dutch\InitialDutchPairingEngine;
use OpenPairing\Tests\Fixtures\TournamentFactory;
use PHPUnit\Framework\TestCase;

final class DifferentialClassifierTest extends TestCase
{
    public function testClassifiesExactAndInfrastructureSeparately(): void
    {
        $state = TournamentFactory::initial(4);
        $php = (new InitialDutchPairingEngine())->pair($state);
        $oracle = new OracleResult(
            OracleStatus::Valid,
            array_map(static fn (PairingTable $table): array => ['whitePlayerId' => $table->whitePlayerId, 'blackPlayerId' => $table->blackPlayerId], $php->tables),
            null,
            '',
            '',
            0,
            [],
        );
        self::assertSame(ComparisonCategory::Exact, (new DifferentialClassifier())->classify($state, $php, $oracle));

        $infrastructure = new OracleResult(OracleStatus::Timeout, [], null, '', '', -1, []);
        self::assertSame(ComparisonCategory::InfrastructureError, (new DifferentialClassifier())->classify($state, $php, $infrastructure));
    }

    public function testClassifiesSamePairsWithReversedColours(): void
    {
        $state = TournamentFactory::initial(2);
        $php = (new InitialDutchPairingEngine())->pair($state);
        $reversed = new OracleResult(OracleStatus::Valid, [[
            'whitePlayerId' => $php->tables[0]->blackPlayerId,
            'blackPlayerId' => $php->tables[0]->whitePlayerId,
        ]], null, '', '', 0, []);

        self::assertSame(ComparisonCategory::UpstreamInvalid, (new DifferentialClassifier())->classify($state, $php, $reversed));
    }
}
