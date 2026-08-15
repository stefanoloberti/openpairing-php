<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\ProhibitedPairingGroup;
use OpenPairing\Domain\Tournament\ScheduledBye;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Format\Trf\Trf2026Codec;
use OpenPairing\Tests\Fixtures\DeterministicTournamentSimulator;
use OpenPairing\Verification\TrfPairingChecker;
use PHPUnit\Framework\TestCase;

final class TrfPairingCheckerTest extends TestCase
{
    public function testChecksPairingsAndDeclaredTieBreaksWithEmbeddedEngines(): void
    {
        $report = (new TrfPairingChecker())->check((new Trf2026Codec())->encode($this->completedRoundOne()));

        self::assertSame('exact', $report['status']);
        self::assertSame(1, $report['counts']['roundsChecked']);
        self::assertSame(1, $report['counts']['roundsExact']);
        self::assertSame('exact', $report['tieBreaks']['status']);
        self::assertSame(5, $report['caseCount']);
    }

    public function testHistoricalReplayHonoursRecord260Constraints(): void
    {
        $initial = DeterministicTournamentSimulator::initial(4, 1);
        $constrained = new TournamentState(
            schemaVersion: $initial->schemaVersion,
            round: $initial->round,
            numberOfRounds: $initial->numberOfRounds,
            pairingSystem: $initial->pairingSystem,
            topColour: $initial->topColour,
            pointsForWin: $initial->pointsForWin,
            pointsForDraw: $initial->pointsForDraw,
            maxPlayers: $initial->maxPlayers,
            players: $initial->players,
            tournamentName: 'Record 260 replay fixture',
            prohibitedPairingGroups: [new ProhibitedPairingGroup(1, 1, [1, 3])],
        );
        $pairing = (new DutchPairingEngine())->pair($constrained);
        $completed = DeterministicTournamentSimulator::advance($constrained, $pairing);

        $report = (new TrfPairingChecker())->check((new Trf2026Codec())->encode($completed));

        self::assertSame('exact', $report['status']);
        self::assertSame(1, $report['counts']['roundsExact']);
        self::assertNotContains(
            '1-3',
            array_map(static function ($table): string {
                $ids = [$table->whitePlayerId, $table->blackPlayerId];
                sort($ids);

                return implode('-', $ids);
            }, $pairing->tables),
        );
    }

    public function testHistoricalReplayHonoursRecord240ScheduledBye(): void
    {
        $initial = DeterministicTournamentSimulator::initial(4, 1);
        $scheduled = new TournamentState(
            schemaVersion: $initial->schemaVersion,
            round: $initial->round,
            numberOfRounds: $initial->numberOfRounds,
            pairingSystem: $initial->pairingSystem,
            topColour: $initial->topColour,
            pointsForWin: $initial->pointsForWin,
            pointsForDraw: $initial->pointsForDraw,
            maxPlayers: $initial->maxPlayers,
            players: $initial->players,
            tournamentName: 'Record 240 replay fixture',
            pointsForPairingAllocatedBye: $initial->pairingAllocatedByeScore(),
            scheduledByes: [new ScheduledBye('F', 1, [4])],
        );
        $pairing = (new DutchPairingEngine())->pair($scheduled);
        $completed = DeterministicTournamentSimulator::advance($scheduled, $pairing);

        $report = (new TrfPairingChecker())->check((new Trf2026Codec())->encode($completed));

        self::assertSame('exact', $report['status']);
        self::assertSame(1, $report['counts']['roundsExact']);
        self::assertTrue($completed->isPlayerScheduledBye(4, 1));
        self::assertSame('F', $completed->players[3]->results[0]['result']);
    }

    public function testReportsSamePairsColourVariantAsDiscrepancy(): void
    {
        $codec = new Trf2026Codec();
        $trf = str_replace(
            ['   3 w 1', '   1 b 0'],
            ['   3 b 1', '   1 w 0'],
            $codec->encode($this->completedRoundOne()),
        );

        $report = (new TrfPairingChecker())->check($trf);
        self::assertSame('discrepancy', $report['status']);
        self::assertSame(1, $report['counts']['roundsSamePairsColourVariant']);
        self::assertSame('same-pairs-colour-variant', $report['rounds'][0]['status']);
    }

    public function testReportsDeclaredRankMismatch(): void
    {
        $codec = new Trf2026Codec();
        $lines = explode("\r\n", $codec->encode($this->completedRoundOne()));
        foreach ($lines as &$line) {
            if (str_starts_with($line, '001    4')) {
                $line = substr_replace($line, '   2', 85, 4);
            }
        }
        unset($line);

        $report = (new TrfPairingChecker())->check(implode("\r\n", $lines));
        self::assertSame('discrepancy', $report['status']);
        self::assertSame('mismatch', $report['tieBreaks']['status']);
        self::assertSame(4, $report['tieBreaks']['mismatches'][0]['playerId']);
    }

    public function testPublicCliReadsStandardInputAndReturnsExactReport(): void
    {
        $trf = (new Trf2026Codec())->encode($this->completedRoundOne());
        [$exitCode, $stdout, $stderr] = $this->runCli(['-'], $trf);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('', $stderr);
        $report = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('exact', $report['status']);
        self::assertSame('openpairing.ptc-report/1', $report['schema']);
    }

    public function testPublicCliReturnsInvalidInputExitCode(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['-'], "012 Invalid\r\n");

        self::assertSame(3, $exitCode);
        self::assertSame('', $stdout);
        $error = json_decode($stderr, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertSame('trf.record_required', $error['code']);
    }

    public function testDistributedNegativeControlReturnsColourVariantExitCode(): void
    {
        $root = dirname(__DIR__, 2);
        [$exitCode, $stdout, $stderr] = $this->runCli([$root.'/examples/ptc-colour-variant.trf']);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        $report = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['counts']);
        self::assertSame('discrepancy', $report['status']);
        self::assertSame(1, $report['counts']['roundsSamePairsColourVariant']);
    }

    public function testPublishedReportSchemaDeclaresCurrentContract(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = json_decode(
            (string) file_get_contents($root.'/docs/schema/ptc-report-v1.schema.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['properties']['schema']);
        self::assertIsArray($schema['properties']['caseCount']);
        self::assertIsArray($schema['required']);
        self::assertSame('openpairing.ptc-report/1', $schema['properties']['schema']['const']);
        self::assertContains('caseCount', $schema['required']);
        self::assertSame(1, $schema['properties']['caseCount']['minimum']);
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $arguments, string $stdin = ''): array
    {
        $root = dirname(__DIR__, 2);
        $pipes = [];
        $process = proc_open([PHP_BINARY, $root.'/bin/openpairing-ptc.php', ...$arguments], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [proc_close($process), $stdout, $stderr];
    }

    private function completedRoundOne(): TournamentState
    {
        return new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: 2,
            numberOfRounds: 1,
            pairingSystem: 'fide-dutch-2026',
            topColour: Colour::White,
            pointsForWin: new Score(2, 2),
            pointsForDraw: new Score(1, 2),
            maxPlayers: 4,
            players: [
                $this->player(1, 3, Colour::White, '1', new Score(2, 2), 1),
                $this->player(2, 4, Colour::Black, '0', Score::zero(), 3),
                $this->player(3, 1, Colour::Black, '0', Score::zero(), 3),
                $this->player(4, 2, Colour::White, '1', new Score(2, 2), 1),
            ],
            tournamentName: 'PTC fixture',
            pointsForLoss: Score::zero(),
            pointsForZeroPointBye: Score::zero(),
            pointsForPairingAllocatedBye: new Score(2, 2),
            pointsForUnknown: new Score(1, 2),
            pairingController: 'OpenPairingPHP',
            tieBreaksRecord: '202',
            tieBreaks: 'BH',
        );
    }

    private function player(
        int $id,
        int $opponent,
        Colour $colour,
        string $result,
        Score $score,
        int $rank,
    ): Player {
        return new Player(
            id: $id,
            pairingNumber: $id,
            name: 'Player '.$id,
            rating: 2100 - ($id * 10),
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
            rank: $rank,
        );
    }
}
