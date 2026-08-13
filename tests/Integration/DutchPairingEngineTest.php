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
        self::assertSame('engine.dutch_global', $result->diagnostics[0]->code);
        self::assertContains('pab.selected', array_column($result->trace, 'code'));
        self::assertCount(3, $result->floatAssignments);
        self::assertSame(
            'down',
            array_values(array_filter(
                $result->floatAssignments,
                static fn ($assignment): bool => $assignment->playerId === 5,
            ))[0]->direction->value,
        );
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

    public function testPublicEngineExpiresFloatsAcrossTwoSkippedTournamentRounds(): void
    {
        $engine = new DutchPairingEngine();
        $expired = $engine->pair($this->floatGapState([1, 2]));
        $recent = $engine->pair($this->floatGapState([3, 4]));

        self::assertSame([], $expired->violations);
        self::assertSame([], $recent->violations);
        self::assertSame(['1-7', '2-8', '3-5', '4-6'], $this->opponentPairs($expired));
        self::assertSame(['1-8', '2-5', '3-6', '4-7'], $this->opponentPairs($recent));
        self::assertNotSame($this->opponentPairs($recent), $this->opponentPairs($expired));
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

    /**
     * Same played history, entered either in rounds 1/2 (expired at round 5)
     * or rounds 3/4 (still UF1/DF1). The distinct expected pairings prove the
     * public engine actually consumes tournament-round identity.
     *
     * @param array{int, int} $rounds
     */
    private function floatGapState(array $rounds): TournamentState
    {
        $games = [
            1 => [[5, Colour::White, '0'], [6, Colour::White, '1']],
            2 => [[6, Colour::White, '1'], [7, Colour::White, '0']],
            3 => [[7, Colour::White, '1'], [8, Colour::White, '1']],
            4 => [[8, Colour::White, '1'], [5, Colour::White, '1']],
            5 => [[1, Colour::Black, '1'], [4, Colour::Black, '0']],
            6 => [[2, Colour::Black, '0'], [1, Colour::Black, '0']],
            7 => [[3, Colour::Black, '0'], [2, Colour::Black, '1']],
            8 => [[4, Colour::Black, '0'], [3, Colour::Black, '0']],
        ];
        $scores = [1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 1, 6 => 0, 7 => 1, 8 => 0];
        $floats = [
            1 => ['none', 'none'], 2 => ['none', 'down'],
            3 => ['none', 'down'], 4 => ['none', 'none'],
            5 => ['none', 'none'], 6 => ['none', 'none'],
            7 => ['none', 'up'], 8 => ['none', 'up'],
        ];
        $players = [];
        foreach ($games as $id => $history) {
            $results = [];
            $opponents = [];
            $colours = [];
            foreach ($history as $index => [$opponent, $colour, $result]) {
                $results[] = [
                    'round' => $rounds[$index],
                    'opponentId' => $opponent,
                    'colour' => $colour->value,
                    'result' => $result,
                    'played' => true,
                ];
                $opponents[] = $opponent;
                $colours[] = $colour;
            }
            $players[] = new Player(
                id: $id,
                pairingNumber: $id,
                name: 'Gap Player '.$id,
                rating: 2400 - $id,
                title: '',
                team: '',
                club: '',
                active: true,
                score: new Score($scores[$id], 1),
                opponentHistory: $opponents,
                colourHistory: $colours,
                results: $results,
                floatHistory: $floats[$id],
            );
        }

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 5,
            numberOfRounds: 7,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
        );
    }

    /** @return list<string> */
    private function opponentPairs(\OpenPairing\Domain\Pairing\PairingResult $result): array
    {
        $pairs = [];
        foreach ($result->tables as $table) {
            $ids = [$table->whitePlayerId, $table->blackPlayerId];
            sort($ids, SORT_NUMERIC);
            $pairs[] = implode('-', $ids);
        }
        sort($pairs, SORT_STRING);

        return $pairs;
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
