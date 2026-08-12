<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Tests\Fixtures\DeterministicTournamentSimulator;
use OpenPairing\Validation\PairingResultValidator;
use PHPUnit\Framework\TestCase;

final class BursteinPairingEngineTest extends TestCase
{
    public function testBsnOrderAndBursteinInitialColoursAreExactWhenThereAreNoSeedingRounds(): void
    {
        $state = DeterministicTournamentSimulator::initial(8, 1, 'fide-burstein-2026');

        $result = (new BursteinPairingEngine())->pair($state);

        self::assertSame([
            ['board' => 1, 'whitePlayerId' => 1, 'blackPlayerId' => 8],
            ['board' => 2, 'whitePlayerId' => 7, 'blackPlayerId' => 2],
            ['board' => 3, 'whitePlayerId' => 3, 'blackPlayerId' => 6],
            ['board' => 4, 'whitePlayerId' => 5, 'blackPlayerId' => 4],
        ], array_map(static fn ($table): array => $table->toArray(), $result->tables));
    }

    public function testBursteinByeRankingUsesLowestRankAfterScoreAndPlayedGames(): void
    {
        $state = DeterministicTournamentSimulator::initial(7, 1, 'fide-burstein-2026');

        $result = (new BursteinPairingEngine())->pair($state);

        self::assertSame(7, $result->byePlayerId);
        self::assertSame([], (new PairingResultValidator())->validate($state, $result));
    }

    public function testConfiguredSeedingRoundsAreExactlyDutch(): void
    {
        $bursteinState = DeterministicTournamentSimulator::initial(8, 6, 'fide-burstein-2026');
        $dutchState = DeterministicTournamentSimulator::initial(8, 6);

        $burstein = (new BursteinPairingEngine())->pair($bursteinState);
        $dutch = (new DutchPairingEngine())->pair($dutchState);

        self::assertSame($dutch->byePlayerId, $burstein->byePlayerId);
        self::assertSame(
            array_map(static fn ($table): array => $table->toArray(), $dutch->tables),
            array_map(static fn ($table): array => $table->toArray(), $burstein->tables),
        );
        self::assertStringContainsString('seeding-dutch', $burstein->algorithmVersion);
    }

    public function testPostSeedingRoundIsCompleteValidAndDeterministic(): void
    {
        $state = DeterministicTournamentSimulator::initial(8, 4, 'fide-burstein-2026');
        $engine = new BursteinPairingEngine();
        for ($round = 1; $round <= 2; ++$round) {
            $state = DeterministicTournamentSimulator::advance($state, $engine->pair($state));
        }

        $first = $engine->pair($state);
        $second = $engine->pair($state);

        self::assertSame(BursteinPairingEngine::VERSION, $first->algorithmVersion);
        self::assertCount(4, $first->tables);
        self::assertSame([], $first->violations);
        self::assertSame([], (new PairingResultValidator())->validate($state, $first));
        self::assertSame($first->toArray(), $second->toArray());
    }

    public function testOddPostSeedingRoundAssignsOneEligibleByeWithoutLosingPlayers(): void
    {
        $state = DeterministicTournamentSimulator::initial(7, 4, 'fide-burstein-2026');
        $engine = new BursteinPairingEngine();
        for ($round = 1; $round <= 2; ++$round) {
            $pairing = $engine->pair($state);
            self::assertNotNull($pairing->byePlayerId);
            $state = DeterministicTournamentSimulator::advance($state, $pairing);
        }

        $result = $engine->pair($state);

        self::assertNotNull($result->byePlayerId);
        self::assertCount(3, $result->tables);
        self::assertSame([], (new PairingResultValidator())->validate($state, $result));
    }

    public function testMatcherLimitReturnsExplicitResourceFailureWithoutPartialPairing(): void
    {
        $state = DeterministicTournamentSimulator::initial(24, 1, 'fide-burstein-2026');

        $result = (new BursteinPairingEngine())->pair($state);

        self::assertSame([], $result->tables);
        self::assertCount(24, $result->unpairedPlayerIds);
        self::assertSame('engine.resource_limit', $result->violations[0]->code);
    }
}
