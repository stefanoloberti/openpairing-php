<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Fixtures;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;

final class TournamentFactory
{
    public static function initial(int $players, Colour $topColour = Colour::White): TournamentState
    {
        $entries = [];
        for ($number = 1; $number <= $players; ++$number) {
            $entries[] = new Player(
                id: 1000 + $number,
                pairingNumber: $number,
                name: 'Player '.$number,
                rating: 2401 - $number,
                title: '',
                team: '',
                club: '',
                active: true,
                score: Score::zero(),
            );
        }

        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 1,
            numberOfRounds: 9,
            pairingSystem: 'fide-dutch-2026',
            topColour: $topColour,
            pointsForWin: new Score(2, 2),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 256,
            players: $entries,
        );
    }
}

