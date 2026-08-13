<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Format\Trf\Trf2026Codec;
use PHPUnit\Framework\TestCase;

final class Trf2026CodecTest extends TestCase
{
    public function testWritesAndReadsSupportedSecondRoundStateWithoutLoss(): void
    {
        $codec = new Trf2026Codec();
        $state = $this->roundTwoState();
        $trf = $codec->encode($state);

        self::assertStringContainsString("012 Codec Cup\r\n", $trf);
        self::assertStringContainsString("192 FIDE_DUTCH_2025\r\n", $trf);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $trf));

        $decoded = $codec->decode($trf);
        self::assertSame(2, $decoded->round);
        self::assertSame('Codec Cup', $decoded->tournamentName);
        self::assertSame('Rome', $decoded->city);
        self::assertSame(['Deputy One', 'Deputy Two'], $decoded->deputyArbiters);
        self::assertSame(['26/08/11', '26/08/12'], $decoded->roundDates);
        self::assertSame('202', $decoded->tieBreaksRecord);
        self::assertSame('BH/C1', $decoded->tieBreaks);
        self::assertSame(Colour::White, $decoded->topColour);
        self::assertSame($trf, $codec->encode($decoded));
        self::assertSame([3], $decoded->players[0]->opponentHistory);
        self::assertSame([Colour::White], $decoded->players[0]->colourHistory);
    }

    public function testDefaultsScoringWhenRecord162IsAbsent(): void
    {
        $codec = new Trf2026Codec();
        $trf = preg_replace('/^162.*\r\n/m', '', $codec->encode($this->roundTwoState()));
        self::assertIsString($trf);
        $state = $codec->decode($trf);

        self::assertSame(0, $state->pointsForWin->compare(new Score(2, 2)));
        self::assertSame(0, $state->pointsForDraw->compare(new Score(1, 2)));
        self::assertSame(0, $state->pairingAllocatedByeScore()->compare(new Score(2, 2)));
    }

    public function testRoundRobinRecordRoundTripsForStandings(): void
    {
        $base = $this->roundTwoState();
        $state = new TournamentState(
            schemaVersion: $base->schemaVersion,
            round: $base->round,
            numberOfRounds: $base->numberOfRounds,
            pairingSystem: 'fide-round-robin',
            topColour: $base->topColour,
            pointsForWin: $base->pointsForWin,
            pointsForDraw: $base->pointsForDraw,
            maxPlayers: $base->maxPlayers,
            players: $base->players,
            tournamentName: 'Round Robin Cup',
            pointsForLoss: $base->lossScore(),
            pointsForZeroPointBye: $base->zeroPointByeScore(),
            pointsForPairingAllocatedBye: $base->pairingAllocatedByeScore(),
            pointsForUnknown: $base->unknownScore(),
            tournamentType: 'Individual Round Robin',
            tieBreaksRecord: '212',
            tieBreaks: 'PTS,KS,DE',
        );
        $codec = new Trf2026Codec();
        $trf = $codec->encode($state);
        $decoded = $codec->decode($trf);

        self::assertStringContainsString("192 FIDE_ROUNDROBIN\r\n", $trf);
        self::assertSame('fide-round-robin', $decoded->pairingSystem);
        self::assertSame('PTS,KS,DE', $decoded->tieBreaks);
    }

    public function testRejectsUnsupportedRecordInsteadOfDroppingIt(): void
    {
        $trf = "082 4\r\n".(new Trf2026Codec())->encode($this->roundTwoState());
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('outside the supported subset');
        (new Trf2026Codec())->decode($trf);
    }

    public function testRejectsOversizedInputBeforeRecordParsing(): void
    {
        try {
            (new Trf2026Codec())->decode(str_repeat(' ', Trf2026Codec::MAX_BYTES + 1));
            self::fail('Oversized TRF must fail closed.');
        } catch (InvalidTournamentState $exception) {
            self::assertSame('trf.too_large', $exception->diagnosticCode);
        }
    }

    public function testRejectsARecordLineOverTheConfiguredLimit(): void
    {
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('exceeds 4096 bytes');
        (new Trf2026Codec())->decode('012 '.str_repeat('A', 4093));
    }

    public function testRejectsDeclaredPlayerCountMismatch(): void
    {
        $trf = str_replace('062 4', '062 5', (new Trf2026Codec())->encode($this->roundTwoState()));
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('does not match the number');
        (new Trf2026Codec())->decode($trf);
    }

    public function testRejectsBothTieBreakRecordAlternatives(): void
    {
        $trf = "212 PTS,BH\r\n".(new Trf2026Codec())->encode($this->roundTwoState());
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('mutually exclusive');
        (new Trf2026Codec())->decode($trf);
    }

    public function testRejectsUnknownOpponent(): void
    {
        $trf = str_replace('   3 w 1', ' 999 w 1', (new Trf2026Codec())->encode($this->roundTwoState()));
        $this->expectException(InvalidTournamentState::class);
        (new Trf2026Codec())->decode($trf);
    }

    public function testRejectsNonReciprocalResult(): void
    {
        $trf = str_replace('   1 b 0', '   2 b 0', (new Trf2026Codec())->encode($this->roundTwoState()));
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('no reciprocal opponent record');
        (new Trf2026Codec())->decode($trf);
    }

    public function testRejectsIncompatibleColour(): void
    {
        $trf = str_replace('   1 b 0', '   1 w 0', (new Trf2026Codec())->encode($this->roundTwoState()));
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('incompatible colours');
        (new Trf2026Codec())->decode($trf);
    }

    public function testRejectsScoreThatDoesNotMatchResults(): void
    {
        $trf = str_replace(' 1.0    1', ' 0.5    1', (new Trf2026Codec())->encode($this->roundTwoState()), $replacements);
        self::assertSame(1, $replacements);
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('score does not equal');
        (new Trf2026Codec())->decode($trf);
    }

    public function testRoundTripPreservesBursteinPairingSystem(): void
    {
        $codec = new Trf2026Codec();
        $dutch = $this->roundTwoState();
        $burstein = new TournamentState(
            schemaVersion: $dutch->schemaVersion,
            round: $dutch->round,
            numberOfRounds: $dutch->numberOfRounds,
            pairingSystem: 'fide-burstein-2026',
            topColour: $dutch->topColour,
            pointsForWin: $dutch->pointsForWin,
            pointsForDraw: $dutch->pointsForDraw,
            maxPlayers: $dutch->maxPlayers,
            players: $dutch->players,
            tournamentName: $dutch->tournamentName,
        );

        $trf = $codec->encode($burstein);

        self::assertStringContainsString("192 FIDE_BURSTEIN\r\n", $trf);
        self::assertSame('fide-burstein-2026', $codec->decode($trf)->pairingSystem);
    }

    public function testRecord250RoundTripsAsRoundIndexedVirtualPoints(): void
    {
        $codec = new Trf2026Codec();
        $trf = $codec->encode($this->roundTwoState());
        $trf = str_replace(
            "001    1",
            "250  0.0  1.0   1   3    1    2\r\n250  0.0  0.5   4   6    1    2\r\n001    1",
            $trf,
        );

        $decoded = $codec->decode($trf);
        self::assertSame('1.0', $decoded->players[0]->acceleration?->toDecimal(1));
        self::assertSame(
            ['1.0', '1.0', '1.0', '0.5', '0.5', '0.5'],
            array_map(static fn (?Score $score): ?string => $score?->toDecimal(1), $decoded->players[0]->accelerationSchedule),
        );
        self::assertSame([], $decoded->players[2]->accelerationSchedule);

        $normalised = $codec->encode($decoded);
        self::assertStringContainsString("250       1.0   1   3    1    2\r\n", $normalised);
        self::assertStringContainsString("250       0.5   4   6    1    2\r\n", $normalised);
        $roundTrip = $codec->decode($normalised);
        self::assertEquals($decoded->players[0]->accelerationSchedule, $roundTrip->players[0]->accelerationSchedule);
    }

    public function testRecord250RejectsConflictingOverlaps(): void
    {
        $trf = (new Trf2026Codec())->encode($this->roundTwoState());
        $trf = "250  0.0  1.0   1   2    1    1\r\n250  0.0  0.5   2   3    1    1\r\n".$trf;

        try {
            (new Trf2026Codec())->decode($trf);
            self::fail('Conflicting acceleration ranges must fail closed.');
        } catch (InvalidTournamentState $exception) {
            self::assertSame('trf.acceleration_overlap', $exception->diagnosticCode);
        }
    }

    public function testRecord250RejectsUnknownCompetitor(): void
    {
        $trf = "250  0.0  1.0   1   2    9    9\r\n".(new Trf2026Codec())->encode($this->roundTwoState());

        try {
            (new Trf2026Codec())->decode($trf);
            self::fail('Acceleration for an unknown competitor must fail closed.');
        } catch (InvalidTournamentState $exception) {
            self::assertSame('trf.acceleration_player', $exception->diagnosticCode);
        }
    }

    public function testCurrentAccelerationWithoutScheduleWritesCurrentRound250(): void
    {
        $state = $this->roundTwoState();
        $players = $state->players;
        $source = $players[0];
        $players[0] = new Player(
            id: $source->id, pairingNumber: $source->pairingNumber, name: $source->name,
            rating: $source->rating, title: $source->title, team: $source->team,
            club: $source->club, active: $source->active, score: $source->score,
            opponentHistory: $source->opponentHistory, colourHistory: $source->colourHistory,
            results: $source->results, receivedPairingAllocatedBye: $source->receivedPairingAllocatedBye,
            floatHistory: $source->floatHistory, acceleration: new Score(1, 2),
            sex: $source->sex, federation: $source->federation, fideId: $source->fideId,
            birthDate: $source->birthDate, rank: $source->rank,
        );
        $accelerated = new TournamentState(
            schemaVersion: $state->schemaVersion, round: $state->round,
            numberOfRounds: $state->numberOfRounds, pairingSystem: $state->pairingSystem,
            topColour: $state->topColour, pointsForWin: $state->pointsForWin,
            pointsForDraw: $state->pointsForDraw, maxPlayers: $state->maxPlayers,
            players: $players, tournamentName: $state->tournamentName,
        );

        $trf = (new Trf2026Codec())->encode($accelerated);

        self::assertStringContainsString("250       0.5   2   2    1    1\r\n", $trf);
        self::assertSame('0.5', (new Trf2026Codec())->decode($trf)->players[0]->acceleration?->toDecimal(1));
    }

    private function roundTwoState(): TournamentState
    {
        $players = [
            $this->player(1, 'Alpha, A', 2400, new Score(2, 2), 3, Colour::White, '1'),
            $this->player(2, 'Beta, B', 2300, Score::zero(), 4, Colour::Black, '0'),
            $this->player(3, 'Gamma, C', 2200, Score::zero(), 1, Colour::Black, '0'),
            $this->player(4, 'Delta, D', 2100, new Score(2, 2), 2, Colour::White, '1'),
        ];

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 2,
            numberOfRounds: 7,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(2, 2),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
            tournamentName: 'Codec Cup',
            pointsForLoss: Score::zero(),
            pointsForZeroPointBye: Score::zero(),
            pointsForPairingAllocatedBye: new Score(2, 2),
            pointsForUnknown: new Score(1, 2),
            city: 'Rome',
            federation: 'ITA',
            startDate: '2026/08/11',
            endDate: '2026/08/17',
            tournamentType: 'Individual Swiss',
            chiefArbiter: 'Chief Arbiter',
            deputyArbiters: ['Deputy One', 'Deputy Two'],
            timeControlDescription: '90 minutes plus 30 seconds per move',
            roundDates: ['26/08/11', '26/08/12'],
            pairingController: 'OpenPairingPHP',
            tieBreaksRecord: '202',
            tieBreaks: 'BH/C1',
            encodedTimeControl: '5400+30',
        );
    }

    private function player(int $id, string $name, int $rating, Score $score, int $opponent, Colour $colour, string $result): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: $name,
            rating: $rating,
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
            receivedPairingAllocatedBye: false,
            floatHistory: ['none'],
            acceleration: null,
            sex: $id % 2 === 0 ? 'w' : 'm',
            federation: 'ITA',
            fideId: 1000000 + $id,
            birthDate: '2000/01/0'.$id,
            rank: $id,
        );
    }
}
