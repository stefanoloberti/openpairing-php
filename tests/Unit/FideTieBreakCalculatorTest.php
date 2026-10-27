<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\TieBreak\FideTieBreakCalculator;
use OpenPairing\Domain\TieBreak\FideTieBreakCode;
use OpenPairing\Domain\TieBreak\FideTieBreakRanker;
use OpenPairing\Domain\TieBreak\FideRatingTable;
use OpenPairing\Domain\TieBreak\TieBreakDefinition;
use OpenPairing\Domain\TieBreak\TieBreakDescriptorParser;
use OpenPairing\Domain\TieBreak\TieBreakModifier;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FideTieBreakCalculatorTest extends TestCase
{
    /** @return iterable<string, array{FideTieBreakCode, TieBreakModifier, string}> */
    public static function playerOneValues(): iterable
    {
        yield 'Buchholz' => [FideTieBreakCode::Buchholz, TieBreakModifier::None, '4.5'];
        yield 'Buchholz Cut-1' => [FideTieBreakCode::Buchholz, TieBreakModifier::Cut1, '3.5'];
        yield 'Buchholz Cut-2' => [FideTieBreakCode::Buchholz, TieBreakModifier::Cut2, '2.0'];
        yield 'Buchholz Median-1' => [FideTieBreakCode::Buchholz, TieBreakModifier::Median1, '1.5'];
        yield 'Buchholz Median-2' => [FideTieBreakCode::Buchholz, TieBreakModifier::Median2, '0.0'];
        yield 'Sonneborn-Berger' => [FideTieBreakCode::SonnebornBerger, TieBreakModifier::None, '2.0'];
        yield 'Progressive Score' => [FideTieBreakCode::ProgressiveScore, TieBreakModifier::None, '4.0'];
        yield 'Progressive Score Cut-1' => [FideTieBreakCode::ProgressiveScore, TieBreakModifier::Cut1, '3.0'];
        yield 'Progressive Score Cut-2' => [FideTieBreakCode::ProgressiveScore, TieBreakModifier::Cut2, '1.5'];
        yield 'number of wins' => [FideTieBreakCode::NumberOfWins, TieBreakModifier::None, '1.0'];
        yield 'games won' => [FideTieBreakCode::NumberOfGamesWon, TieBreakModifier::None, '1.0'];
        yield 'black games' => [FideTieBreakCode::BlackGamesPlayed, TieBreakModifier::None, '1.0'];
        yield 'black wins' => [FideTieBreakCode::BlackGamesWon, TieBreakModifier::None, '0.0'];
        yield 'elected rounds' => [FideTieBreakCode::RoundsElectedToPlay, TieBreakModifier::None, '3.0'];
        yield 'standard points' => [FideTieBreakCode::StandardPoints, TieBreakModifier::None, '1.5'];
        yield 'points descriptor' => [FideTieBreakCode::Points, TieBreakModifier::None, '1.5'];
        yield 'pairing number' => [FideTieBreakCode::TournamentPairingNumber, TieBreakModifier::None, '1.0'];
        yield 'ARO' => [FideTieBreakCode::AverageRatingOfOpponents, TieBreakModifier::None, '2000.0'];
        yield 'ARO Cut-1' => [FideTieBreakCode::AverageRatingOfOpponents, TieBreakModifier::Cut1, '2050.0'];
        yield 'ARO Median-1' => [FideTieBreakCode::AverageRatingOfOpponents, TieBreakModifier::Median1, '2000.0'];
        yield 'ARO Median-2' => [FideTieBreakCode::AverageRatingOfOpponents, TieBreakModifier::Median2, '0.0'];
        yield 'rating' => [FideTieBreakCode::Rating, TieBreakModifier::None, '2200.0'];
        yield 'Fore Buchholz' => [FideTieBreakCode::ForeBuchholz, TieBreakModifier::None, '4.0'];
        yield 'Fore Buchholz Cut-2' => [FideTieBreakCode::ForeBuchholz, TieBreakModifier::Cut2, '1.5'];
        yield 'Fore Buchholz Median-1' => [FideTieBreakCode::ForeBuchholz, TieBreakModifier::Median1, '1.5'];
        yield 'Average of Buchholz' => [FideTieBreakCode::AverageOfOpponentsBuchholz, TieBreakModifier::None, '4.5'];
        yield 'Koya' => [FideTieBreakCode::KoyaSystem, TieBreakModifier::None, '1.0'];
        yield 'Tournament Performance Rating' => [FideTieBreakCode::TournamentPerformanceRating, TieBreakModifier::None, '2000.0'];
        yield 'Perfect Tournament Performance' => [FideTieBreakCode::PerfectTournamentPerformance, TieBreakModifier::None, '1999.0'];
        yield 'Average Performance Rating of Opponents' => [FideTieBreakCode::AveragePerformanceRatingOfOpponents, TieBreakModifier::None, '2067.0'];
        yield 'Average Perfect Performance of Opponents' => [FideTieBreakCode::AveragePerfectPerformanceOfOpponents, TieBreakModifier::None, '2063.0'];
    }

    public function testForeAobUsesForeBuchholzAndRoundsToHundredths(): void
    {
        $definition = (new TieBreakDescriptorParser())->parse('AOB/F');
        $value = (new FideTieBreakCalculator())->evaluate($this->roundRobinState(), $definition)[1];

        self::assertSame('4.67', $value->toDecimal(2));
        self::assertSame('AOB/F', $definition->identifier());
    }

    public function testDescriptorParserSupportsEveryIndividualVariantFamily(): void
    {
        $definitions = (new TieBreakDescriptorParser())->parseList(
            'DE/P, SB/C2/P, ARO/M2, BH/M1/P, AOB/F, FB/M2/P, KS/L-2, TPR, PTP, APRO, APPO',
        );

        self::assertSame(
            ['DE/P', 'SB/C2/P', 'ARO/M2', 'BH/M1/P', 'AOB/F', 'FB/M2/P', 'KS/L-2', 'TPR', 'PTP', 'APRO', 'APPO'],
            array_map(static fn (TieBreakDefinition $definition): string => $definition->identifier(), $definitions),
        );
    }

    public function testEveryMandatoryIndividualRankOrderDescriptorParses(): void
    {
        $descriptors = [
            'DE', 'DE/P', 'BPG', 'BWG', 'REP',
            'SB', 'SB/C1', 'SB/C2', 'SB/P', 'SB/C1/P', 'SB/C2/P',
            'ARO', 'ARO/C1', 'ARO/C2', 'ARO/M1', 'ARO/M2', 'TPR', 'PTP', 'APRO', 'APPO',
            'WIN', 'WON', 'PS', 'PS/C1', 'PS/C2',
            'BH', 'BH/C1', 'BH/C2', 'BH/M1', 'BH/M2',
            'BH/P', 'BH/C1/P', 'BH/C2/P', 'BH/M1/P', 'BH/M2/P',
            'AOB', 'AOB/F',
            'FB', 'FB/C1', 'FB/C2', 'FB/M1', 'FB/M2',
            'FB/P', 'FB/C1/P', 'FB/C2/P', 'FB/M1/P', 'FB/M2/P',
            'KS', 'KS/L+1', 'KS/L-2',
        ];
        $parsed = (new TieBreakDescriptorParser())->parseList(strtolower(implode(',', $descriptors)));

        self::assertSame($descriptors, array_map(
            static fn (TieBreakDefinition $definition): string => $definition->identifier(),
            $parsed,
        ));
    }

    public function testTrf212TrailingCommaIsTheDocumentedEquivalentOfRecord202(): void
    {
        $definitions = (new TieBreakDescriptorParser())->parseList('PTS,');

        self::assertCount(1, $definitions);
        self::assertSame('PTS', $definitions[0]->identifier());
    }

    public function testTournamentApplicabilityFailsClosed(): void
    {
        $calculator = new FideTieBreakCalculator();

        try {
            $calculator->evaluate($this->roundRobinSystemState(), (new TieBreakDescriptorParser())->parse('BH'));
            self::fail('Buchholz must be rejected for a round robin.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('must not be used', $exception->getMessage());
        }
        try {
            $calculator->evaluate($this->roundRobinState(), (new TieBreakDescriptorParser())->parse('KS'));
            self::fail('Koya must be rejected outside a round robin.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('only to round-robin', $exception->getMessage());
        }
    }

    public function testOfficialRatingTableBoundariesAreExact(): void
    {
        $table = new FideRatingTable();

        self::assertSame(-800, $table->ratingDifferenceForPercentage(0));
        self::assertSame(0, $table->ratingDifferenceForPercentage(50));
        self::assertSame(677, $table->ratingDifferenceForPercentage(99));
        self::assertSame(800, $table->ratingDifferenceForPercentage(100));
        self::assertSame(50, $table->expectedScoreHundredths(2000, 2000));
        self::assertSame(51, $table->expectedScoreHundredths(2004, 2000));
        self::assertSame(99, $table->expectedScoreHundredths(2735, 2000));
        self::assertSame(100, $table->expectedScoreHundredths(2736, 2000));
    }

    public function testPinnedGacruxRegressionMatchesAllNewCalculationFamilies(): void
    {
        $path = dirname(__DIR__).'/Regression/gacrux-individual-tiebreak-round-robin.json';
        $regression = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($regression);
        self::assertIsArray($regression['descriptors']);
        self::assertIsArray($regression['valuesByPlayer']);
        $state = $this->roundRobinState();
        $calculator = new FideTieBreakCalculator();
        $parser = new TieBreakDescriptorParser();

        foreach ($regression['descriptors'] as $descriptorIndex => $descriptor) {
            self::assertIsString($descriptor);
            $calculationState = $descriptor === 'KS' ? $this->roundRobinSystemState() : $state;
            $values = $calculator->evaluate($calculationState, $parser->parse($descriptor));
            foreach ($values as $playerId => $value) {
                $expectedByPlayer = $regression['valuesByPlayer'][(string) $playerId];
                self::assertIsArray($expectedByPlayer);
                $expected = $expectedByPlayer[$descriptorIndex];
                self::assertIsString($expected);
                $dot = strpos($expected, '.');
                $digits = $dot === false ? 0 : strlen($expected) - $dot - 1;
                self::assertSame($expected, $value->toDecimal($digits), sprintf('%s player %d', $descriptor, $playerId));
            }
        }
    }

    public function testKoyaLimitMovesInHalfPointSteps(): void
    {
        $calculator = new FideTieBreakCalculator();
        $state = $this->roundRobinSystemState();

        self::assertSame(
            '0.0',
            $calculator->evaluate($state, (new TieBreakDescriptorParser())->parse('KS/L+1'))[1]->toDecimal(1),
        );
        self::assertSame(
            '1.5',
            $calculator->evaluate($state, (new TieBreakDescriptorParser())->parse('KS/L-1'))[1]->toDecimal(1),
        );
    }

    public function testCompleteRankOrderCanPlacePointsAfterAnotherCriterion(): void
    {
        $ranking = (new FideTieBreakRanker())->rankByCriteria(
            $this->roundRobinState(),
            (new TieBreakDescriptorParser())->parseList('RTNG,PTS'),
        );

        self::assertSame([[1], [2], [3], [4]], $ranking->groups);
        self::assertSame(1, $ranking->standings($this->roundRobinState())[0]['rank']);
        self::assertArrayHasKey('PTS', $ranking->evaluations);
    }

    public function testTrf212TournamentRecordDrivesCompleteStandingsOrder(): void
    {
        $state = $this->roundRobinState();
        $recordState = new TournamentState(
            schemaVersion: $state->schemaVersion,
            round: $state->round,
            numberOfRounds: $state->numberOfRounds,
            pairingSystem: $state->pairingSystem,
            topColour: $state->topColour,
            pointsForWin: $state->pointsForWin,
            pointsForDraw: $state->pointsForDraw,
            maxPlayers: $state->maxPlayers,
            players: $state->players,
            tournamentType: $state->tournamentType,
            tieBreaksRecord: '212',
            tieBreaks: 'RTNG,PTS',
        );

        self::assertSame(
            [[1], [2], [3], [4]],
            (new FideTieBreakRanker())->rankFromTournamentRecord($recordState)->groups,
        );
    }

    public function testOptionPTreatsScheduledForfeitsAsPlayed(): void
    {
        $state = $this->forfeitState();
        $parser = new TieBreakDescriptorParser();
        $calculator = new FideTieBreakCalculator();

        self::assertSame(
            '0.0',
            $calculator->evaluate($state, $parser->parse('BH'))[2]->toDecimal(1),
        );
        self::assertSame(
            '1.0',
            $calculator->evaluate($state, $parser->parse('BH/P'))[2]->toDecimal(1),
        );
        self::assertSame(
            [[1], [2]],
            (new FideTieBreakRanker())->rankByCriteria($state, [$parser->parse('DE/P')])->groups,
        );
    }

    #[DataProvider('playerOneValues')]
    public function testOfficialIndividualDefinitions(
        FideTieBreakCode $code,
        TieBreakModifier $modifier,
        string $expected,
    ): void {
        $state = $code === FideTieBreakCode::KoyaSystem
            ? $this->roundRobinSystemState()
            : $this->roundRobinState();
        $value = (new FideTieBreakCalculator())->evaluate(
            $state,
            new TieBreakDefinition($code, $modifier),
        )[1];

        self::assertSame($expected, $value->toDecimal(1));
    }

    public function testUnplayedRoundManagementAdjustsTrailingRequestedByesForOpponents(): void
    {
        $state = $this->unplayedState();
        $calculator = new FideTieBreakCalculator();

        $buchholz = $calculator->evaluate($state, new TieBreakDefinition(FideTieBreakCode::Buchholz));
        $cut = $calculator->evaluate($state, new TieBreakDefinition(FideTieBreakCode::Buchholz, TieBreakModifier::Cut1));

        self::assertSame('4.0', $buchholz[1]->toDecimal(1));
        self::assertSame('5.0', $buchholz[2]->toDecimal(1));
        self::assertSame('3.0', $cut[1]->toDecimal(1));
    }

    public function testDirectEncounterResolvesThePlayersTiedOnTournamentScore(): void
    {
        $ranking = (new FideTieBreakRanker())->rank(
            $this->roundRobinState(),
            [new TieBreakDefinition(FideTieBreakCode::DirectEncounter)],
        );

        self::assertSame([[4], [1], [2], [3]], $ranking->groups);
    }

    public function testOrderedTieBreakListRetainsUnresolvedGroups(): void
    {
        $ranking = (new FideTieBreakRanker())->rank(
            $this->roundRobinState(),
            [
                new TieBreakDefinition(FideTieBreakCode::Buchholz),
                new TieBreakDefinition(FideTieBreakCode::TournamentPairingNumber),
            ],
        );

        self::assertSame([[4], [1], [2], [3]], $ranking->groups);
        self::assertArrayHasKey('BH', $ranking->evaluations);
        self::assertArrayHasKey('TPN', $ranking->evaluations);
    }

    public function testGameWinsUseTheResultCodeWhenDrawAndWinPointsAreEqual(): void
    {
        $players = [
            new Player(
                id: 1,
                pairingNumber: 1,
                name: 'White',
                rating: 2000,
                title: '',
                team: '',
                club: '',
                active: true,
                score: new Score(1, 1),
                opponentHistory: [2],
                colourHistory: [Colour::White],
                results: [['round' => 1, 'opponentId' => 2, 'colour' => 'white', 'result' => '=', 'played' => true]],
                floatHistory: ['none'],
            ),
            new Player(
                id: 2,
                pairingNumber: 2,
                name: 'Black',
                rating: 1900,
                title: '',
                team: '',
                club: '',
                active: true,
                score: new Score(1, 1),
                opponentHistory: [1],
                colourHistory: [Colour::Black],
                results: [['round' => 1, 'opponentId' => 1, 'colour' => 'black', 'result' => '=', 'played' => true]],
                floatHistory: ['none'],
            ),
        ];
        $state = new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 2,
            numberOfRounds: 1,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 1),
            maxPlayers: 256,
            players: $players,
        );
        $calculator = new FideTieBreakCalculator();

        self::assertSame('1.0', $calculator->evaluate($state, new TieBreakDefinition(FideTieBreakCode::NumberOfWins))[1]->toDecimal(1));
        self::assertSame('0.0', $calculator->evaluate($state, new TieBreakDefinition(FideTieBreakCode::NumberOfGamesWon))[1]->toDecimal(1));
        self::assertSame('0.0', $calculator->evaluate($state, new TieBreakDefinition(FideTieBreakCode::BlackGamesWon))[2]->toDecimal(1));
    }

    private function roundRobinState(): TournamentState
    {
        $ratings = [1 => 2200, 2 => 2100, 3 => 2000, 4 => 1900];
        $scores = [1 => '1.5', 2 => '1.5', 3 => '1.0', 4 => '2.0'];
        $rounds = [
            1 => [
                ['round' => 1, 'opponentId' => 2, 'colour' => 'white', 'result' => '1', 'played' => true],
                ['round' => 2, 'opponentId' => 3, 'colour' => 'black', 'result' => '=', 'played' => true],
                ['round' => 3, 'opponentId' => 4, 'colour' => 'white', 'result' => '0', 'played' => true],
            ],
            2 => [
                ['round' => 1, 'opponentId' => 1, 'colour' => 'black', 'result' => '0', 'played' => true],
                ['round' => 2, 'opponentId' => 4, 'colour' => 'white', 'result' => '1', 'played' => true],
                ['round' => 3, 'opponentId' => 3, 'colour' => 'black', 'result' => '=', 'played' => true],
            ],
            3 => [
                ['round' => 1, 'opponentId' => 4, 'colour' => 'white', 'result' => '0', 'played' => true],
                ['round' => 2, 'opponentId' => 1, 'colour' => 'white', 'result' => '=', 'played' => true],
                ['round' => 3, 'opponentId' => 2, 'colour' => 'white', 'result' => '=', 'played' => true],
            ],
            4 => [
                ['round' => 1, 'opponentId' => 3, 'colour' => 'black', 'result' => '1', 'played' => true],
                ['round' => 2, 'opponentId' => 2, 'colour' => 'black', 'result' => '0', 'played' => true],
                ['round' => 3, 'opponentId' => 1, 'colour' => 'black', 'result' => '1', 'played' => true],
            ],
        ];
        $players = [];
        foreach ($rounds as $id => $results) {
            $players[] = $this->player($id, $ratings[$id], $scores[$id], $results);
        }

        return $this->state($players, 4, 3);
    }

    private function roundRobinSystemState(): TournamentState
    {
        $state = $this->roundRobinState();

        return new TournamentState(
            schemaVersion: $state->schemaVersion,
            round: $state->round,
            numberOfRounds: $state->numberOfRounds,
            pairingSystem: 'fide-round-robin',
            topColour: $state->topColour,
            pointsForWin: $state->pointsForWin,
            pointsForDraw: $state->pointsForDraw,
            maxPlayers: $state->maxPlayers,
            players: $state->players,
            tournamentType: 'Round Robin',
        );
    }

    private function unplayedState(): TournamentState
    {
        $playerOneResults = [
            ['round' => 1, 'opponentId' => 2, 'colour' => 'white', 'result' => '1', 'played' => true],
            ['round' => 2, 'opponentId' => null, 'colour' => null, 'result' => 'Z', 'played' => false],
            ['round' => 3, 'opponentId' => null, 'colour' => null, 'result' => 'Z', 'played' => false],
        ];
        $playerTwoResults = [
            ['round' => 1, 'opponentId' => 1, 'colour' => 'black', 'result' => '0', 'played' => true],
            ['round' => 2, 'opponentId' => null, 'colour' => null, 'result' => 'F', 'played' => false],
            ['round' => 3, 'opponentId' => null, 'colour' => null, 'result' => 'F', 'played' => false],
        ];

        return $this->state([
            $this->player(1, 2000, '1.0', $playerOneResults),
            $this->player(2, 1900, '2.0', $playerTwoResults),
        ], 4, 3);
    }

    private function forfeitState(): TournamentState
    {
        return $this->state([
            $this->player(1, 2000, '1.0', [[
                'round' => 1,
                'opponentId' => 2,
                'colour' => null,
                'result' => '+',
                'played' => false,
            ]]),
            $this->player(2, 1900, '0.0', [[
                'round' => 1,
                'opponentId' => 1,
                'colour' => null,
                'result' => '-',
                'played' => false,
            ]]),
        ], 2, 1);
    }

    /**
     * @param list<array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool}> $results
     */
    private function player(int $id, int $rating, string $score, array $results): Player
    {
        $opponents = [];
        $colours = [];
        foreach ($results as $result) {
            if ($result['played'] && $result['opponentId'] !== null) {
                $opponents[] = $result['opponentId'];
                $colours[] = Colour::from((string) $result['colour']);
            }
        }

        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: $rating,
            title: '',
            team: '',
            club: '',
            active: true,
            score: Score::fromDecimal($score),
            opponentHistory: $opponents,
            colourHistory: $colours,
            results: $results,
            floatHistory: array_fill(0, count($results), 'none'),
        );
    }

    /** @param list<Player> $players */
    private function state(array $players, int $round, int $numberOfRounds): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: $round,
            numberOfRounds: $numberOfRounds,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
        );
    }
}
