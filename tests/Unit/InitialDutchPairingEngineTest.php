<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Engine\Dutch\InitialDutchPairingEngine;
use OpenPairing\Tests\Fixtures\TournamentFactory;
use OpenPairing\Validation\PairingResultValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InitialDutchPairingEngineTest extends TestCase
{
    /** @return iterable<string, array{int, string, list<array{int, int}>, int|null}> */
    public static function initialCases(): iterable
    {
        yield 'four, white top' => [4, 'white', [[1001, 1003], [1004, 1002]], null];
        yield 'four, black top' => [4, 'black', [[1003, 1001], [1002, 1004]], null];
        yield 'five, white top' => [5, 'white', [[1001, 1003], [1004, 1002]], 1005];
        yield 'six, white top' => [6, 'white', [[1001, 1004], [1005, 1002], [1003, 1006]], null];
    }

    /** @param list<array{int, int}> $expected */
    #[DataProvider('initialCases')]
    public function testPairsInitialRoundDeterministically(int $count, string $top, array $expected, ?int $bye): void
    {
        $state = TournamentFactory::initial($count, Colour::from($top));
        $engine = new InitialDutchPairingEngine();
        $before = (new \OpenPairing\Format\Json\CanonicalJson())->encode($state);
        $first = $engine->pair($state);
        $second = $engine->pair($state);

        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame($before, (new \OpenPairing\Format\Json\CanonicalJson())->encode($state));
        self::assertSame($bye, $first->byePlayerId);
        self::assertSame(
            $expected,
            array_map(static fn ($table): array => [$table->whitePlayerId, $table->blackPlayerId], $first->tables),
        );
        self::assertSame([], (new PairingResultValidator())->validate($state, $first));
        if ($bye !== null) {
            self::assertSame(
                [['playerId' => $bye, 'direction' => 'down']],
                array_map(static fn ($assignment): array => $assignment->toArray(), $first->floatAssignments),
            );
        }
    }

    public function testFailsClosedForLaterRounds(): void
    {
        $initial = TournamentFactory::initial(4);
        $later = new \OpenPairing\Domain\Tournament\TournamentState(
            $initial->schemaVersion,
            2,
            $initial->numberOfRounds,
            $initial->pairingSystem,
            $initial->topColour,
            $initial->pointsForWin,
            $initial->pointsForDraw,
            $initial->maxPlayers,
            $initial->players,
        );

        $this->expectException(InvalidTournamentState::class);
        (new InitialDutchPairingEngine())->pair($later);
    }

    public function testPairsFiveHundredPlayersWithoutAnArchitecturalCeiling(): void
    {
        $state = TournamentFactory::initial(500);
        $result = (new InitialDutchPairingEngine())->pair($state);

        self::assertCount(250, $result->tables);
        self::assertNull($result->byePlayerId);
        self::assertSame([], (new PairingResultValidator())->validate($state, $result));
    }
}
