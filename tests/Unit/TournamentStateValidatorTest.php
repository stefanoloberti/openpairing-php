<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Validation\TournamentStateValidator;
use PHPUnit\Framework\TestCase;

final class TournamentStateValidatorTest extends TestCase
{
    public function testAcceptsAReciprocalCompletedRound(): void
    {
        (new TournamentStateValidator())->validate($this->state());
        self::addToAssertionCount(1);
    }

    public function testRejectsCanonicalHistoryThatDisagreesWithResults(): void
    {
        $state = $this->state(true);
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('histories do not equal');
        (new TournamentStateValidator())->validate($state);
    }

    public function testRejectsNegativeVirtualPoints(): void
    {
        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('negative virtual points');
        (new TournamentStateValidator())->validate($this->state(acceleration: new Score(-1, 2)));
    }

    public function testRejectsFloatHistoryThatDoesNotCoverCompletedResults(): void
    {
        $state = $this->state();
        $player = $state->players[0];
        $replacement = new Player(
            id: $player->id,
            pairingNumber: $player->pairingNumber,
            name: $player->name,
            rating: $player->rating,
            title: $player->title,
            team: $player->team,
            club: $player->club,
            active: $player->active,
            score: $player->score,
            opponentHistory: $player->opponentHistory,
            colourHistory: $player->colourHistory,
            results: $player->results,
            floatHistory: [],
        );
        $invalid = new TournamentState(
            schemaVersion: $state->schemaVersion,
            round: $state->round,
            numberOfRounds: $state->numberOfRounds,
            pairingSystem: $state->pairingSystem,
            topColour: $state->topColour,
            pointsForWin: $state->pointsForWin,
            pointsForDraw: $state->pointsForDraw,
            maxPlayers: $state->maxPlayers,
            players: [$replacement, $state->players[1]],
        );

        $this->expectException(InvalidTournamentState::class);
        $this->expectExceptionMessage('float history does not cover');
        (new TournamentStateValidator())->validate($invalid);
    }

    public function testRejectsConfiguredCapacityAbovePublicGuardRail(): void
    {
        $state = $this->state();
        $invalid = new TournamentState(
            schemaVersion: $state->schemaVersion,
            round: $state->round,
            numberOfRounds: $state->numberOfRounds,
            pairingSystem: $state->pairingSystem,
            topColour: $state->topColour,
            pointsForWin: $state->pointsForWin,
            pointsForDraw: $state->pointsForDraw,
            maxPlayers: TournamentState::MAX_PLAYERS + 1,
            players: $state->players,
        );

        try {
            (new TournamentStateValidator())->validate($invalid);
            self::fail('Capacity above the public guard-rail must fail closed.');
        } catch (InvalidTournamentState $exception) {
            self::assertSame('players.limit', $exception->diagnosticCode);
        }
    }

    private function state(bool $badHistory = false, ?Score $acceleration = null): TournamentState
    {
        $white = new Player(
            id: 10,
            pairingNumber: 1,
            name: 'White',
            rating: 2100,
            title: '',
            team: '',
            club: '',
            active: true,
            score: new Score(1, 1),
            opponentHistory: $badHistory ? [] : [20],
            colourHistory: [Colour::White],
            results: [['round' => 1, 'opponentId' => 20, 'colour' => 'white', 'result' => '1', 'played' => true]],
            floatHistory: ['none'],
            acceleration: $acceleration,
        );
        $black = new Player(
            id: 20,
            pairingNumber: 2,
            name: 'Black',
            rating: 2000,
            title: '',
            team: '',
            club: '',
            active: true,
            score: Score::zero(),
            opponentHistory: [10],
            colourHistory: [Colour::Black],
            results: [['round' => 1, 'opponentId' => 10, 'colour' => 'black', 'result' => '0', 'played' => true]],
            floatHistory: ['none'],
        );

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 2,
            numberOfRounds: 5,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: [$white, $black],
        );
    }
}
