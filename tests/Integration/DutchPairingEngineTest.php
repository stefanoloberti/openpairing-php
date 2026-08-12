<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Validation\PairingResultValidator;
use PHPUnit\Framework\TestCase;

final class DutchPairingEngineTest extends TestCase
{
    public function testInitialColoursFollowCurrentS1OrderWhenTopSeedIsInactive(): void
    {
        $state = $this->state([
            $this->player(1, active: false),
            $this->player(2),
            $this->player(3),
            $this->player(4),
            $this->player(5),
            $this->player(6),
            $this->player(7),
        ], 1);

        $result = (new DutchPairingEngine())->pair($state);

        self::assertSame([], $result->violations);
        self::assertSame(
            [[2, 5], [6, 3], [4, 7]],
            array_map(static fn ($table): array => [$table->whitePlayerId, $table->blackPlayerId], $result->tables),
        );
    }

    public function testPairsTwoRoundTwoScoregroupsWithoutRepeats(): void
    {
        $state = $this->fourPlayerRoundTwo();
        $result = (new DutchPairingEngine())->pair($state);

        self::assertNull($result->byePlayerId);
        self::assertCount(2, $result->tables);
        self::assertSame([], $result->violations);
        self::assertSame([], (new PairingResultValidator())->validate($state, $result));
        $pairs = array_map(
            static fn ($table): array => [$table->whitePlayerId, $table->blackPlayerId],
            $result->tables,
        );
        self::assertContains(1, $pairs[0]);
        self::assertContains(4, $pairs[0]);
        self::assertContains(2, $pairs[1]);
        self::assertContains(3, $pairs[1]);
    }

    public function testSelectsLowestScoreEligiblePabAndCompletesViaDownfloat(): void
    {
        $state = $this->fivePlayerRoundTwo();
        $result = (new DutchPairingEngine())->pair($state);

        self::assertSame(5, $result->byePlayerId);
        self::assertCount(2, $result->tables);
        self::assertSame([], $result->violations);
        self::assertStringContainsString('1 downfloater', $result->trace[1]['detail']);
        self::assertCount(2, $result->floatAssignments);
        self::assertSame(['down', 'up'], array_map(static fn ($assignment): string => $assignment->direction->value, $result->floatAssignments));
    }

    public function testReturnsExplicitNoSolutionWithoutPartialPairing(): void
    {
        $state = $this->twoPlayersWhoAlreadyMet();
        $result = (new DutchPairingEngine())->pair($state);

        self::assertSame([], $result->tables);
        self::assertSame([1, 2], $result->unpairedPlayerIds);
        self::assertSame('engine.no_solution', $result->violations[0]->code);
    }

    public function testVirtualPointsCreateFirstRoundPairingScoregroupsForBothSystems(): void
    {
        $dutchState = $this->acceleratedInitialState('fide-dutch-2026');
        $bursteinState = $this->acceleratedInitialState('fide-burstein-2026');

        $dutch = (new DutchPairingEngine())->pair($dutchState);
        $burstein = (new BursteinPairingEngine())->pair($bursteinState);

        self::assertSame([], (new PairingResultValidator())->validate($dutchState, $dutch));
        self::assertSame([], (new PairingResultValidator())->validate($bursteinState, $burstein));
        self::assertSame(
            array_map(static fn ($table): array => $table->toArray(), $dutch->tables),
            array_map(static fn ($table): array => $table->toArray(), $burstein->tables),
        );
        foreach ($dutch->tables as $table) {
            self::assertSame($table->whitePlayerId <= 4, $table->blackPlayerId <= 4);
        }
    }

    /** Regression minimized from public Gacrux corpus T0016, entering round 3. */
    public function testHomogeneousOrderPrefersTopHalfAsFirstPairMembers(): void
    {
        $state = $this->t0016RoundThree();

        $result = (new DutchPairingEngine())->pair($state);
        $pairs = array_map(static function ($table): array {
            $pair = [$table->whitePlayerId, $table->blackPlayerId];
            sort($pair);

            return $pair;
        }, $result->tables);
        sort($pairs);

        self::assertSame([], $result->violations);
        self::assertSame(4, $result->byePlayerId);
        self::assertSame([[1, 5], [2, 6], [3, 7]], $pairs);
    }

    private function fourPlayerRoundTwo(): TournamentState
    {
        return $this->state([
            $this->playedPlayer(1, 3, Colour::White, '1', new Score(1, 1)),
            $this->playedPlayer(2, 4, Colour::Black, '0', Score::zero()),
            $this->playedPlayer(3, 1, Colour::Black, '0', Score::zero()),
            $this->playedPlayer(4, 2, Colour::White, '1', new Score(1, 1)),
        ]);
    }

    private function fivePlayerRoundTwo(): TournamentState
    {
        return $this->state([
            $this->playedPlayer(1, 4, Colour::White, '1', new Score(1, 1)),
            $this->playedPlayer(2, 5, Colour::White, '1', new Score(1, 1)),
            new Player(
                id: 3,
                pairingNumber: 3,
                name: 'Player 3',
                rating: 2197,
                title: '',
                team: '',
                club: '',
                active: true,
                score: new Score(1, 1),
                results: [['round' => 1, 'opponentId' => null, 'colour' => null, 'result' => 'U', 'played' => false]],
                receivedPairingAllocatedBye: true,
                floatHistory: ['down'],
            ),
            $this->playedPlayer(4, 1, Colour::Black, '0', Score::zero()),
            $this->playedPlayer(5, 2, Colour::Black, '0', Score::zero()),
        ]);
    }

    private function twoPlayersWhoAlreadyMet(): TournamentState
    {
        return $this->state([
            $this->playedPlayer(1, 2, Colour::White, '1', new Score(1, 1)),
            $this->playedPlayer(2, 1, Colour::Black, '0', Score::zero()),
        ]);
    }

    private function playedPlayer(int $id, int $opponent, Colour $colour, string $result, Score $score): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2200 - $id,
            title: '',
            team: '',
            club: '',
            active: true,
            score: $score,
            opponentHistory: [$opponent],
            colourHistory: [$colour],
            results: [[
                'round' => 1,
                'opponentId' => $opponent,
                'colour' => $colour->value,
                'result' => $result,
                'played' => true,
            ]],
            floatHistory: ['none'],
        );
    }

    private function acceleratedInitialState(string $pairingSystem): TournamentState
    {
        $players = [];
        for ($id = 1; $id <= 8; ++$id) {
            $players[] = new Player(
                id: $id,
                pairingNumber: $id,
                name: 'Player '.$id,
                rating: 2400 - $id,
                title: '',
                team: '',
                club: '',
                active: true,
                score: Score::zero(),
                acceleration: $id <= 4 ? new Score(1, 1) : null,
            );
        }

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 1,
            numberOfRounds: 9,
            pairingSystem: $pairingSystem,
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
        );
    }

    private function t0016RoundThree(): TournamentState
    {
        $rounds = [
            1 => [[4, Colour::White, '1', true], [7, Colour::Black, '=', true]],
            2 => [[5, Colour::Black, '=', true], [3, Colour::White, '=', true]],
            3 => [[6, Colour::White, '1', true], [2, Colour::Black, '=', true]],
            4 => [[1, Colour::Black, '0', true], [5, Colour::White, '0', true]],
            5 => [[2, Colour::White, '=', true], [4, Colour::Black, '1', true]],
            6 => [[3, Colour::Black, '0', true], [null, null, 'U', false]],
            7 => [[null, null, 'U', false], [1, Colour::White, '=', true]],
        ];
        $scores = [1 => '1.5', 2 => '1.0', 3 => '1.5', 4 => '0.0', 5 => '1.5', 6 => '1.0', 7 => '1.5'];
        $floats = [
            1 => ['none', 'none'], 2 => ['none', 'up'], 3 => ['none', 'down'],
            4 => ['none', 'up'], 5 => ['none', 'down'], 6 => ['none', 'none'], 7 => ['none', 'none'],
        ];
        $players = [];
        foreach ($rounds as $id => $history) {
            $results = [];
            $opponents = [];
            $colours = [];
            foreach ($history as $roundIndex => [$opponent, $colour, $result, $played]) {
                $results[] = [
                    'round' => $roundIndex + 1,
                    'opponentId' => $opponent,
                    'colour' => $colour?->value,
                    'result' => $result,
                    'played' => $played,
                ];
                if ($played) {
                    $opponents[] = $opponent;
                    $colours[] = $colour;
                }
            }
            $players[] = new Player(
                id: $id,
                pairingNumber: $id,
                name: 'Player '.$id,
                rating: 2240 - ($id * 20),
                title: '',
                team: '',
                club: '',
                active: true,
                score: Score::fromDecimal($scores[$id]),
                opponentHistory: $opponents,
                colourHistory: $colours,
                results: $results,
                receivedPairingAllocatedBye: in_array($id, [6, 7], true),
                floatHistory: $floats[$id],
            );
        }

        return $this->state($players, 3);
    }

    private function player(int $id, bool $active = true): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2200 - $id,
            title: '',
            team: '',
            club: '',
            active: $active,
            score: Score::zero(),
        );
    }

    /** @param list<Player> $players */
    private function state(array $players, int $round = 2): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: $round,
            numberOfRounds: 5,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
            pointsForPairingAllocatedBye: new Score(1, 1),
        );
    }
}
