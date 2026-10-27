<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Diagnostics\Diagnostic;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Pairing\PairingTable;
use OpenPairing\Domain\Tournament\ProhibitedPairingGroup;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Tests\Fixtures\DeterministicTournamentSimulator;
use OpenPairing\Tests\Fixtures\TournamentFactory;
use OpenPairing\Validation\PairingResultValidator;
use PHPUnit\Framework\TestCase;

final class PairingResultValidatorTest extends TestCase
{
    public function testRejectsReversedColourAllocation(): void
    {
        $state = TournamentFactory::initial(2);
        $result = $this->pairingResult([new PairingTable(1, 1002, 1001)]);

        self::assertContains(
            'colour.allocation',
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, (new PairingResultValidator())->validate($state, $result)),
        );
    }

    public function testRejectsARepeatedOpponent(): void
    {
        $state = DeterministicTournamentSimulator::initial(4, 4);
        $state = DeterministicTournamentSimulator::advance($state, (new DutchPairingEngine())->pair($state));
        $previousOpponent = $state->players[0]->opponentHistory[0];
        $result = $this->pairingResult(
            [new PairingTable(1, $state->players[0]->id, $previousOpponent)],
            null,
            array_values(array_filter(
                array_map(static fn ($player): int => $player->id, $state->players),
                static fn (int $id): bool => !in_array($id, [$state->players[0]->id, $previousOpponent], true),
            )),
            [new Diagnostic('test.failure', 'Synthetic invalid result.', 'error')],
        );

        self::assertContains(
            'pair.repeat',
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, (new PairingResultValidator())->validate($state, $result)),
        );
    }

    public function testRejectsAProhibitedPairingWithSpecificDiagnostic(): void
    {
        $base = TournamentFactory::initial(2);
        $left = $base->players[0]->id;
        $right = $base->players[1]->id;
        $state = new TournamentState(
            schemaVersion: $base->schemaVersion,
            round: $base->round,
            numberOfRounds: $base->numberOfRounds,
            pairingSystem: $base->pairingSystem,
            topColour: $base->topColour,
            pointsForWin: $base->pointsForWin,
            pointsForDraw: $base->pointsForDraw,
            maxPlayers: $base->maxPlayers,
            players: $base->players,
            prohibitedPairingGroups: [new ProhibitedPairingGroup(1, 1, [$left, $right])],
        );
        $result = $this->pairingResult([new PairingTable(1, $left, $right)]);

        self::assertContains(
            'pair.prohibited',
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, (new PairingResultValidator())->validate($state, $result)),
        );
    }

    public function testRejectsASecondPairingAllocatedBye(): void
    {
        $state = DeterministicTournamentSimulator::initial(3, 4);
        $first = (new DutchPairingEngine())->pair($state);
        self::assertNotNull($first->byePlayerId);
        $state = DeterministicTournamentSimulator::advance($state, $first);
        $other = array_values(array_filter(
            $state->players,
            static fn ($player): bool => $player->id !== $first->byePlayerId,
        ));
        $result = $this->pairingResult(
            [new PairingTable(1, $other[0]->id, $other[1]->id)],
            $first->byePlayerId,
        );

        self::assertContains(
            'bye.ineligible',
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, (new PairingResultValidator())->validate($state, $result)),
        );
    }

    /**
     * @param list<PairingTable> $tables
     * @param list<int> $unpaired
     * @param list<Diagnostic> $violations
     */
    private function pairingResult(
        array $tables,
        ?int $bye = null,
        array $unpaired = [],
        array $violations = [],
    ): PairingResult {
        return new PairingResult(
            tables: $tables,
            byePlayerId: $bye,
            unpairedPlayerIds: $unpaired,
            diagnostics: [],
            violations: $violations,
            qualityVector: [],
            trace: [],
            algorithmVersion: 'synthetic-test',
        );
    }
}
