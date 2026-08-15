<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ShadowEvidenceVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-shadow-evidence-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testCompleteIdentityScopedBatchPasses(): void
    {
        $events = [
            $this->event('event-white', 'white', 'exact', [
                'odd-field', 'withdrawal', 'pab', 'forfeit',
            ], 'b'),
            $this->event('event-black', 'black', 'exact', [
                'even-field', 'scheduled-bye', 'accelerated',
            ], 'c'),
        ];
        [$manifest, $stream] = $this->writeBatch($events);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $stream, 2);

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('GO', $report['status']);
        self::assertSame(2, $report['counts']['attempted']);
        self::assertSame(2, $report['counts']['observed']);
        self::assertSame([], $report['blockers']);
    }

    public function testInfrastructureObservationIsNoGo(): void
    {
        $events = [
            $this->event('event-white', 'white', 'exact', [
                'odd-field', 'withdrawal', 'pab', 'forfeit',
            ], 'b'),
            $this->event('event-black', 'black', 'infrastructure-error', [
                'even-field', 'scheduled-bye', 'accelerated',
            ], null),
        ];
        [$manifest, $stream] = $this->writeBatch($events);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $stream, 1);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertSame('unobserved-or-infrastructure', $report['blockers'][0]['code']);
    }

    public function testMixedIdentityIsInfrastructureError(): void
    {
        $first = $this->event('event-white', 'white', 'exact', [
            'odd-field', 'withdrawal', 'pab', 'forfeit',
        ], 'b');
        $second = $this->event('event-black', 'black', 'exact', [
            'even-field', 'scheduled-bye', 'accelerated',
        ], 'c');
        $differentIdentity = $this->identity();
        $differentIdentity['productVersion'] = 'different-build';
        $second['identity'] = $differentIdentity;
        [$manifest, $stream] = $this->writeBatch([$first, $second]);

        [$exit, , $stderr] = $this->runVerifier($manifest, $stream, 2);

        self::assertSame(2, $exit);
        self::assertStringContainsString('identity differs from the batch identity', $stderr);
    }

    public function testAttemptDenominatorMismatchIsInfrastructureError(): void
    {
        [$manifest, $stream] = $this->writeBatch([
            $this->event('event-white', 'white', 'exact', [
                'odd-field', 'even-field', 'withdrawal', 'pab', 'forfeit',
                'scheduled-bye', 'accelerated',
            ], 'b'),
        ]);
        $batch = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($batch);
        /** @var array<string, mixed> $batch */
        $batch['attemptedTurns'] = 2;
        self::assertIsInt(file_put_contents($manifest, json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        [$exit, , $stderr] = $this->runVerifier($manifest, $stream, 1);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Attempt denominator mismatch', $stderr);
    }

    public function testUnclassifiedNonExactTurnIsNoGo(): void
    {
        $variant = $this->event('event-black', 'black', 'legal-variant', [
            'even-field', 'scheduled-bye', 'accelerated',
        ], 'c');
        $variant['primaryOutputSha256'] = str_repeat('d', 64);
        $events = [
            $this->event('event-white', 'white', 'exact', [
                'odd-field', 'withdrawal', 'pab', 'forfeit',
            ], 'b'),
            $variant,
        ];
        [$manifest, $stream] = $this->writeBatch($events);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $stream, 2);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertSame('unclassified-non-exact', $report['blockers'][0]['code']);
    }

    public function testPersonalDataKeyIsInfrastructureError(): void
    {
        $event = $this->event('event-white', 'white', 'exact', [
            'odd-field', 'even-field', 'withdrawal', 'pab', 'forfeit',
            'scheduled-bye', 'accelerated',
        ], 'b');
        $event['playerName'] = 'must not leave controlled environment';
        [$manifest, $stream] = $this->writeBatch([$event]);

        [$exit, , $stderr] = $this->runVerifier($manifest, $stream, 1);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Personal-data key is forbidden', $stderr);
    }

    public function testLocalViolationIsRepresentedAsNoGo(): void
    {
        $event = $this->event('event-local-violation', 'white', 'local-violation', [
            'odd-field', 'even-field', 'withdrawal', 'pab', 'forfeit',
            'scheduled-bye', 'accelerated',
        ], 'b');
        $event['validatorOutcome'] = 'fail';
        $event['classificationReference'] = 'validator-report-sha256:'.str_repeat('4', 64);
        $event['reproductionReference'] = 'fixture-sha256:'.str_repeat('5', 64);
        [$manifest, $stream] = $this->writeBatch([$event]);

        [$exit, $stdout, $stderr] = $this->runVerifier($manifest, $stream, 1);

        self::assertSame(1, $exit, $stderr);
        $report = $this->decodeReport($stdout);
        self::assertSame('NO_GO', $report['status']);
        self::assertSame('blocking-outcomes', $report['blockers'][0]['code']);
        self::assertSame(0, $report['counts']['unclassifiedNonExact']);
    }

    public function testNonExactStatusCannotReuseExactFunctionalHash(): void
    {
        $event = $this->event('event-false-variant', 'black', 'legal-variant', [
            'odd-field', 'even-field', 'withdrawal', 'pab', 'forfeit',
            'scheduled-bye', 'accelerated',
        ], 'd');
        $event['classificationReference'] = 'classification-sha256:'.str_repeat('4', 64);
        $event['reproductionReference'] = 'fixture-sha256:'.str_repeat('5', 64);
        [$manifest, $stream] = $this->writeBatch([$event]);

        [$exit, , $stderr] = $this->runVerifier($manifest, $stream, 1);

        self::assertSame(2, $exit);
        self::assertStringContainsString('identical functional hashes', $stderr);
    }

    /**
     * @param list<string> $features
     * @return array<string, mixed>
     */
    private function event(string $eventId, string $initialColour, string $status, array $features, ?string $shadowHash): array
    {
        $functionalHash = $shadowHash === null ? null : str_repeat($shadowHash, 64);

        return [
            'schema' => 'openpairing.shadow-turn/1',
            'eventId' => $eventId,
            'observedAtUtc' => '2026-08-16T00:00:00Z',
            'identity' => $this->identity(),
            'tournament' => [
                'mode' => 'fide',
                'pairingSystem' => 'fide-dutch-2026',
                'round' => 3,
                'playerCount' => $initialColour === 'white' ? 81 : 80,
                'initialColour' => $initialColour,
            ],
            'canonicalInputSha256' => str_repeat('a', 64),
            'primaryOutputSha256' => $functionalHash ?? str_repeat('d', 64),
            'shadowOutputSha256' => $functionalHash,
            'primaryElapsedMs' => 10.5,
            'shadowElapsedMs' => $functionalHash === null ? 0 : 12.5,
            'shadowPeakMemoryBytes' => $functionalHash === null ? 0 : 1_048_576,
            'status' => $status,
            'validatorOutcome' => $functionalHash === null ? 'not-run' : 'pass',
            'features' => $features,
        ];
    }

    /** @return array<string, string> */
    private function identity(): array
    {
        return [
            'productVersion' => 'chesspairings-shadow-1',
            'productBuildSha256' => str_repeat('1', 64),
            'packageVersion' => '1.0.0',
            'engineVersion' => 'fide-dutch-2026.global/1',
            'sourceTreeSha256' => str_repeat('2', 64),
            'runtimeArchiveSha256' => str_repeat('3', 64),
            'algorithmVersion' => 'fide-dutch-2026',
            'stateSchemaVersion' => 'openpairing.state/1',
            'arithmeticBackend' => 'gmp',
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{string, string}
     */
    private function writeBatch(array $events): array
    {
        $stream = $this->temporary.'/events.ndjson';
        $lines = array_map(
            static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $events,
        );
        self::assertIsInt(file_put_contents($stream, implode("\n", $lines)."\n"));
        $eventsHash = hash_file('sha256', $stream);
        self::assertIsString($eventsHash);
        $manifest = $this->temporary.'/manifest.json';
        self::assertIsInt(file_put_contents($manifest, json_encode([
            'schema' => 'openpairing.shadow-evidence-batch/1',
            'identity' => $this->identity(),
            'attemptedTurns' => count($events),
            'eventsSha256' => $eventsHash,
            'budgets' => [
                'maximumShadowElapsedMs' => 1_000,
                'maximumShadowPeakMemoryBytes' => 2_097_152,
            ],
            'fixtureEvidence' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));

        return [$manifest, $stream];
    }

    /** @return array{status: string, counts: array{attempted: int, observed: int, unclassifiedNonExact: int}, blockers: list<array{code: string}>} */
    private function decodeReport(string $json): array
    {
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report)) {
            self::fail('Shadow evidence verifier did not return a JSON object.');
        }

        /** @var array{status: string, counts: array{attempted: int, observed: int, unclassifiedNonExact: int}, blockers: list<array{code: string}>} $report */
        return $report;
    }

    /** @return array{int, string, string} */
    private function runVerifier(string $manifest, string $events, int $minimumTurns): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/verify_shadow_evidence.php',
            $manifest,
            $events,
            '--minimum-turns='.$minimumTurns,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }
}
