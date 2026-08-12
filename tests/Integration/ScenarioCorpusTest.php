<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Burstein\BursteinPairingEngine;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Format\Json\CanonicalJson;
use OpenPairing\Validation\PairingResultValidator;
use PHPUnit\Framework\TestCase;

final class ScenarioCorpusTest extends TestCase
{
    public function testEqualRatingsAndUnratedPlayersRemainDeterministic(): void
    {
        foreach (['fide-dutch-2026', 'fide-burstein-2026'] as $system) {
            $state = $this->initialState(10, $system, static fn (int $id): int => $id <= 5 ? 1800 : 0);
            $engine = $system === 'fide-dutch-2026' ? new DutchPairingEngine() : new BursteinPairingEngine();

            $first = $engine->pair($state);
            $second = $engine->pair($state);

            self::assertSame([], (new PairingResultValidator())->validate($state, $first));
            self::assertSame($first->toArray(), $second->toArray());
        }
    }

    public function testWithdrawnPlayersAreExcludedFromRoundAccounting(): void
    {
        $state = $this->initialState(9, 'fide-dutch-2026', static fn (int $id): int => 2200 - $id, [8, 9]);

        $result = (new DutchPairingEngine())->pair($state);

        self::assertCount(3, $result->tables);
        self::assertNotNull($result->byePlayerId);
        self::assertSame([], (new PairingResultValidator())->validate($state, $result));
        $accounted = [$result->byePlayerId];
        foreach ($result->tables as $table) {
            $accounted[] = $table->whitePlayerId;
            $accounted[] = $table->blackPlayerId;
        }
        sort($accounted);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $accounted);
    }

    public function testCommonThreeOneZeroScoringPairsExactlyForBothSystems(): void
    {
        foreach (['fide-dutch-2026', 'fide-burstein-2026'] as $system) {
            $state = $this->threeOneZeroRoundTwo($system);
            $engine = $system === 'fide-dutch-2026' ? new DutchPairingEngine() : new BursteinPairingEngine();

            $result = $engine->pair($state);

            self::assertCount(3, $result->tables);
            self::assertSame([], $result->violations);
            self::assertSame([], (new PairingResultValidator())->validate($state, $result));
        }
    }

    public function testRequestedUnplayedRoundsRemainPairableAndDoNotCreateColourHistory(): void
    {
        foreach (['fide-dutch-2026', 'fide-burstein-2026'] as $system) {
            $players = [
                $this->playedPlayer(1, 2, Colour::White, '1', new Score(1, 1)),
                $this->playedPlayer(2, 1, Colour::Black, '0', Score::zero()),
                $this->unplayedPlayer(3, 'Z', Score::zero()),
                $this->unplayedPlayer(4, 'F', new Score(1, 1)),
            ];
            $state = $this->state($players, $system, 2, 5);
            $engine = $system === 'fide-dutch-2026' ? new DutchPairingEngine() : new BursteinPairingEngine();

            $result = $engine->pair($state);

            self::assertCount(2, $result->tables);
            self::assertSame([], (new PairingResultValidator())->validate($state, $result));
        }
    }

    public function testExhaustedRoundRobinReturnsNoSolutionWithoutPartialTables(): void
    {
        foreach (['fide-dutch-2026', 'fide-burstein-2026'] as $system) {
            $state = $this->completedFourPlayerRoundRobin($system);
            $engine = $system === 'fide-dutch-2026' ? new DutchPairingEngine() : new BursteinPairingEngine();

            $result = $engine->pair($state);

            self::assertSame([], $result->tables);
            self::assertSame([1, 2, 3, 4], $result->unpairedPlayerIds);
            self::assertSame('engine.no_solution', $result->violations[0]->code);
        }
    }

    public function testPairingNeverMutatesCanonicalInputState(): void
    {
        $codec = new CanonicalJson();
        $state = $this->threeOneZeroRoundTwo('fide-dutch-2026');
        $before = $codec->encode($state);

        (new DutchPairingEngine())->pair($state);

        self::assertSame($before, $codec->encode($state));
    }

    /**
     * @param callable(int): int $rating
     * @param list<int> $withdrawn
     */
    private function initialState(int $count, string $system, callable $rating, array $withdrawn = []): TournamentState
    {
        $players = [];
        foreach (range(1, $count) as $id) {
            $players[] = new Player(
                id: $id,
                pairingNumber: $id,
                name: 'Player '.$id,
                rating: $rating($id),
                title: '',
                team: '',
                club: '',
                active: !in_array($id, $withdrawn, true),
                score: Score::zero(),
            );
        }

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 1,
            numberOfRounds: 1,
            pairingSystem: $system,
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
        );
    }

    private function threeOneZeroRoundTwo(string $system): TournamentState
    {
        $players = [
            $this->playedPlayer(1, 4, Colour::White, '1', new Score(3, 1)),
            $this->playedPlayer(2, 5, Colour::White, '=', new Score(1, 1)),
            $this->playedPlayer(3, 6, Colour::White, '0', Score::zero()),
            $this->playedPlayer(4, 1, Colour::Black, '0', Score::zero()),
            $this->playedPlayer(5, 2, Colour::Black, '=', new Score(1, 1)),
            $this->playedPlayer(6, 3, Colour::Black, '1', new Score(3, 1)),
        ];

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 2,
            numberOfRounds: 3,
            pairingSystem: $system,
            topColour: Colour::White,
            pointsForWin: new Score(3, 1),
            pointsForDraw: new Score(1, 1),
            maxPlayers: 256,
            players: $players,
            pointsForLoss: Score::zero(),
            pointsForPairingAllocatedBye: new Score(3, 1),
        );
    }

    /** @param list<Player> $players */
    private function state(array $players, string $system, int $round, int $numberOfRounds): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: $round,
            numberOfRounds: $numberOfRounds,
            pairingSystem: $system,
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
        );
    }

    private function unplayedPlayer(int $id, string $result, Score $score): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2300 - $id,
            title: '',
            team: '',
            club: '',
            active: true,
            score: $score,
            results: [['round' => 1, 'opponentId' => null, 'colour' => null, 'result' => $result, 'played' => false]],
            floatHistory: ['none'],
        );
    }

    private function completedFourPlayerRoundRobin(string $system): TournamentState
    {
        $schedule = [
            1 => [[2, Colour::White], [3, Colour::Black], [4, Colour::White]],
            2 => [[1, Colour::Black], [4, Colour::White], [3, Colour::Black]],
            3 => [[4, Colour::White], [1, Colour::White], [2, Colour::White]],
            4 => [[3, Colour::Black], [2, Colour::Black], [1, Colour::Black]],
        ];
        $players = [];
        foreach ($schedule as $id => $rounds) {
            $results = [];
            $opponents = [];
            $colours = [];
            foreach ($rounds as $index => [$opponent, $colour]) {
                $results[] = [
                    'round' => $index + 1,
                    'opponentId' => $opponent,
                    'colour' => $colour->value,
                    'result' => '=',
                    'played' => true,
                ];
                $opponents[] = $opponent;
                $colours[] = $colour;
            }
            $players[] = new Player(
                id: $id,
                pairingNumber: $id,
                name: 'Player '.$id,
                rating: 2300 - $id,
                title: '',
                team: '',
                club: '',
                active: true,
                score: new Score(3, 2),
                opponentHistory: $opponents,
                colourHistory: $colours,
                results: $results,
                floatHistory: ['none', 'none', 'none'],
            );
        }

        return $this->state($players, $system, 4, 5);
    }

    private function playedPlayer(int $id, int $opponent, Colour $colour, string $result, Score $score): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2300 - $id,
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
}
