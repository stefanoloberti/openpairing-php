<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\TieBreak\BursteinOppositionEvaluation;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use PHPUnit\Framework\TestCase;

final class BursteinOppositionEvaluationTest extends TestCase
{
    public function testCalculatesExactBuchholzAndSonnebornBerger(): void
    {
        $players = [
            $this->player(1, 2, Colour::White, '1', new Score(1, 1)),
            $this->player(2, 1, Colour::Black, '0', Score::zero()),
            $this->player(3, 4, Colour::White, '=', new Score(1, 2)),
            $this->player(4, 3, Colour::Black, '=', new Score(1, 2)),
        ];
        $values = (new BursteinOppositionEvaluation())->calculate($this->state($players));

        self::assertSame(0, $values[1]->buchholz->compare(Score::zero()));
        self::assertSame(0, $values[2]->buchholz->compare(new Score(1, 1)));
        self::assertSame(0, $values[3]->buchholz->compare(new Score(1, 2)));
        self::assertSame(0, $values[3]->sonnebornBerger->compare(new Score(1, 4)));
    }

    public function testVirtualPointsAreNotPartOfOppositionEvaluation(): void
    {
        $players = [
            $this->player(1, 2, Colour::White, '1', new Score(1, 1), new Score(1, 1)),
            $this->player(2, 1, Colour::Black, '0', Score::zero()),
        ];

        $values = (new BursteinOppositionEvaluation())->calculate($this->state($players));

        self::assertSame(0, $values[2]->buchholz->compare(new Score(1, 1)));
    }

    /** @param list<Player> $players */
    private function state(array $players): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 2,
            numberOfRounds: 5,
            pairingSystem: 'fide-burstein-2026',
            topColour: Colour::White,
            pointsForWin: new Score(1, 1),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $players,
        );
    }

    private function player(int $id, int $opponent, Colour $colour, string $result, Score $score, ?Score $acceleration = null): Player
    {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2000 - $id,
            title: '',
            team: '',
            club: '',
            active: true,
            score: $score,
            opponentHistory: [$opponent],
            colourHistory: [$colour],
            results: [['round' => 1, 'opponentId' => $opponent, 'colour' => $colour->value, 'result' => $result, 'played' => true]],
            floatHistory: ['none'],
            acceleration: $acceleration,
        );
    }
}
