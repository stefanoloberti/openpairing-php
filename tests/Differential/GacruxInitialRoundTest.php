<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Differential;

use OpenPairing\Development\Oracle\GacruxOracleAdapter;
use OpenPairing\Development\Oracle\OracleStatus;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Engine\Dutch\InitialDutchPairingEngine;
use OpenPairing\Tests\Fixtures\TournamentFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('oracle')]
final class GacruxInitialRoundTest extends TestCase
{
    /** @return iterable<string, array{int, Colour}> */
    public static function cases(): iterable
    {
        yield 'two white' => [2, Colour::White];
        yield 'four black' => [4, Colour::Black];
        yield 'five white' => [5, Colour::White];
        yield 'six white' => [6, Colour::White];
        yield 'nine black' => [9, Colour::Black];
    }

    #[DataProvider('cases')]
    public function testExactPairAndColourParityWithGacrux(int $count, Colour $top): void
    {
        $root = dirname(__DIR__, 2);
        $adapter = new GacruxOracleAdapter(
            PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3',
            $root.'/tools/upstream/gacrux',
            $root.'/tools/oracle/gacrux_bridge.py',
        );
        $state = TournamentFactory::initial($count, $top);
        $oracle = $adapter->pair($state);
        self::assertSame(OracleStatus::Valid, $oracle->status, $oracle->stderr);

        $php = (new InitialDutchPairingEngine())->pair($state);
        self::assertSame($php->byePlayerId, $oracle->byePlayerId);
        self::assertSame(
            array_map(static fn ($table): array => ['whitePlayerId' => $table->whitePlayerId, 'blackPlayerId' => $table->blackPlayerId], $php->tables),
            $oracle->pairs,
        );
    }
}
