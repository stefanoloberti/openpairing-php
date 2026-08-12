<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Acceleration\BakuAcceleration;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
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
