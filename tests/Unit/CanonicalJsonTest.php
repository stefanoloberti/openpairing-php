<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Acceleration\BakuAcceleration;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\ProhibitedPairingGroup;
use OpenPairing\Domain\Tournament\ScheduledBye;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Format\Json\CanonicalJson;
use OpenPairing\Tests\Fixtures\TournamentFactory;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function testRoundTripIsCanonicalAndStable(): void
    {
        $codec = new CanonicalJson();
        $state = TournamentFactory::initial(5);
        $encoded = $codec->encode($state);

        self::assertSame($encoded, $codec->encode($codec->decode($encoded)));
        self::assertStringNotContainsString('.0', $encoded);
    }

    public function testRejectsUnknownSchema(): void
    {
        $json = str_replace('openpairing.tournament/1', 'openpairing.tournament/999', (new CanonicalJson())->encode(TournamentFactory::initial(2)));
        $this->expectException(InvalidTournamentState::class);
        (new CanonicalJson())->decode($json);
    }

    public function testRoundTripPreservesProhibitedPairingGroups(): void
    {
        $base = TournamentFactory::initial(4);
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
            prohibitedPairingGroups: [new ProhibitedPairingGroup(1, 3, [1001, 1003])],
        );
        $codec = new CanonicalJson();

        $decoded = $codec->decode($codec->encode($state));

        self::assertTrue($decoded->isPairingProhibited(1001, 1003, 2));
        self::assertFalse($decoded->isPairingProhibited(1001, 1003, 4));
        self::assertSame($codec->encode($state), $codec->encode($decoded));
    }

    public function testRoundTripPreservesScheduledIndividualByes(): void
    {
        $base = TournamentFactory::initial(4);
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
            scheduledByes: [new ScheduledBye('H', 1, [1002])],
        );
        $codec = new CanonicalJson();

        $decoded = $codec->decode($codec->encode($state));

        self::assertTrue($decoded->isPlayerScheduledBye(1002));
        self::assertSame([1001, 1003, 1004], array_map(static fn ($player): int => $player->id, $decoded->activePlayers()));
        self::assertSame($codec->encode($state), $codec->encode($decoded));
    }

    public function testRoundTripPreservesVirtualPointsSeparatelyFromStandingsScore(): void
    {
        $codec = new CanonicalJson();
        $state = (new BakuAcceleration())->apply(TournamentFactory::initial(8));

        $decoded = $codec->decode($codec->encode($state));

        self::assertSame(0, $decoded->players[0]->score->compare(\OpenPairing\Domain\Score\Score::zero()));
        self::assertNotNull($decoded->players[0]->acceleration);
        self::assertSame(0, $decoded->players[0]->pairingScore()->compare($state->pointsForWin));
        self::assertNull($decoded->players[4]->acceleration);
    }

    public function testRoundTripPreservesRoundIndexedAccelerationSchedule(): void
    {
        $codec = new CanonicalJson();
        $json = json_decode($codec->encode(TournamentFactory::initial(2)), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertArrayHasKey('players', $json);
        self::assertIsArray($json['players']);
        self::assertArrayHasKey(0, $json['players']);
        self::assertIsArray($json['players'][0]);
        $json['players'][0]['accelerationSchedule'] = [
            ['units' => 1, 'scale' => 1],
            null,
            ['units' => 1, 'scale' => 2],
        ];
        $json['players'][0]['acceleration'] = ['units' => 1, 'scale' => 1];

        $decoded = $codec->decode(json_encode($json, JSON_THROW_ON_ERROR));

        self::assertSame(
            ['1.0', null, '0.5'],
            array_map(static fn (?Score $score): ?string => $score?->toDecimal(1), $decoded->players[0]->accelerationSchedule),
        );
        self::assertSame($codec->encode($decoded), $codec->encode($codec->decode($codec->encode($decoded))));
    }

    public function testRejectsCurrentAccelerationThatContradictsSchedule(): void
    {
        $codec = new CanonicalJson();
        $json = json_decode($codec->encode(TournamentFactory::initial(2)), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json['players']);
        self::assertIsArray($json['players'][0]);
        $json['players'][0]['acceleration'] = ['units' => 1, 'scale' => 2];
        $json['players'][0]['accelerationSchedule'] = [['units' => 1, 'scale' => 1]];

        try {
            $codec->decode(json_encode($json, JSON_THROW_ON_ERROR));
            self::fail('Contradictory current and scheduled acceleration must fail closed.');
        } catch (InvalidTournamentState $exception) {
            self::assertSame('acceleration.current_mismatch', $exception->diagnosticCode);
        }
    }

    public function testRejectsUnknownPropertyInsteadOfSilentlyDroppingIt(): void
    {
        $json = json_decode((new CanonicalJson())->encode(TournamentFactory::initial(2)), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        $json['unexpected'] = true;

        try {
            (new CanonicalJson())->decode(json_encode($json, JSON_THROW_ON_ERROR));
            self::fail('Unknown properties must fail closed.');
        } catch (InvalidTournamentState $exception) {
            self::assertSame('json.property_unknown', $exception->diagnosticCode);
        }
    }

    public function testRejectsInputOverOneMebibyteBeforeParsing(): void
    {
        try {
            (new CanonicalJson())->decode(str_repeat(' ', CanonicalJson::MAX_BYTES + 1));
            self::fail('Oversized JSON must fail closed.');
        } catch (InvalidTournamentState $exception) {
            self::assertSame('json.too_large', $exception->diagnosticCode);
        }
    }

    public function testRejectsExcessiveJsonDepth(): void
    {
        $json = str_repeat('{"a":', 33).'null'.str_repeat('}', 33);

        try {
            (new CanonicalJson())->decode($json);
            self::fail('Deep JSON must fail closed.');
        } catch (InvalidTournamentState $exception) {
            self::assertSame('json.invalid', $exception->diagnosticCode);
        }
    }
}
