<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/tools/TrfCorpusCompatibility.php';

final class TrfCorpusCompatibilityTest extends TestCase
{
    public function testMissingTopColourIsInferredFromPlayedRoundOneReference(): void
    {
        [$normalized, $transformations, $provenance] = \TrfCorpusCompatibility::normalize(implode("\n", [
            $this->playerLine(1, 3, 'b', '1'),
            $this->playerLine(2, 4, 'w', '1'),
            $this->playerLine(3, 1, 'w', '0'),
            $this->playerLine(4, 2, 'b', '0'),
        ]));

        self::assertStringContainsString("\n152 B\n", "\n".$normalized."\n");
        self::assertContains('infer-top-colour-from-round-one', $transformations);
        self::assertNotContains('default-top-colour-white', $transformations);
        self::assertSame([
            'source' => 'inferred-round-one',
            'colour' => 'B',
            'basis' => ['pairingNumber' => 1, 'rankIndex' => 0, 'recordedColour' => 'B'],
        ], $provenance);
    }

    public function testForfeitColoursDoNotInfluenceTopColourInference(): void
    {
        [$normalized, $transformations, $provenance] = \TrfCorpusCompatibility::normalize(implode("\n", [
            $this->playerLine(1, 4, 'b', '+'),
            $this->playerLine(2, 3, 'b', '1'),
            $this->playerLine(3, 2, 'w', '0'),
            $this->playerLine(4, 1, 'w', '-'),
        ]));

        self::assertStringContainsString("\n152 W\n", "\n".$normalized."\n");
        self::assertContains('clear-legacy-forfeit-colour', $transformations);
        self::assertContains('infer-top-colour-from-round-one', $transformations);
        self::assertSame('W', $provenance['colour']);
        self::assertNotNull($provenance['basis']);
        self::assertSame(2, $provenance['basis']['pairingNumber']);
    }

    public function testExplicitTopColourIsPreserved(): void
    {
        [$normalized, $transformations, $provenance] = \TrfCorpusCompatibility::normalize(implode("\n", [
            $this->playerLine(1, 2, 'b', '1'),
            $this->playerLine(2, 1, 'w', '0'),
            '152 W',
        ]));

        self::assertSame(1, substr_count($normalized, '152 W'));
        self::assertNotContains('infer-top-colour-from-round-one', $transformations);
        self::assertNotContains('default-top-colour-white', $transformations);
        self::assertSame(['source' => 'record-152', 'colour' => 'W', 'basis' => null], $provenance);
    }

    public function testMissingColourFailsClosedWithoutPlayedRoundOneGames(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('initial colour cannot be inferred');
        \TrfCorpusCompatibility::normalize(implode("\n", [
            $this->playerLine(1, 2, 'b', '+'),
            $this->playerLine(2, 1, 'w', '-'),
        ]));
    }

    private function playerLine(int $pairingNumber, int $opponent, string $colour, string $result): string
    {
        $line = str_pad('001 '.str_pad((string) $pairingNumber, 4, ' ', STR_PAD_LEFT), 99);
        $block = '  '.str_pad((string) $opponent, 4, ' ', STR_PAD_LEFT).' '.$colour.' '.$result;

        return substr_replace($line, $block, 89, 10);
    }
}
