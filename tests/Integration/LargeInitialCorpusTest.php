<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Tests\Fixtures\DeterministicTournamentSimulator;
use OpenPairing\Validation\PairingResultValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LargeInitialCorpusTest extends TestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function fields(): iterable
    {
        foreach (['fide-dutch-2026', 'fide-burstein-2026'] as $system) {
            foreach ([2, 3, 16, 32, 64, 128] as $players) {
                yield $system.'-'.$players => [$players, $system];
            }
        }
    }

    #[DataProvider('fields')]
    public function testLargeInitialFieldsAreCompleteValidAndDeterministic(int $players, string $system): void
    {
        $state = DeterministicTournamentSimulator::initial($players, 9, $system);
        $engine = $system === 'fide-dutch-2026' ? new DutchPairingEngine() : new BursteinPairingEngine();

        $first = $engine->pair($state);
        $second = $engine->pair($state);

        self::assertCount(intdiv($players, 2), $first->tables);
        self::assertSame($players % 2 === 1, $first->byePlayerId !== null);
        self::assertSame([], $first->violations);
        self::assertSame([], (new PairingResultValidator())->validate($state, $first));
        self::assertSame($first->toArray(), $second->toArray());
    }
}
