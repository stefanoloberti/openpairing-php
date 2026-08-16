<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ShadowObservationStreamBuilderTest extends TestCase
{
    private string $temporary;
    private string $attempts;
    private string $events;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-shadow-observation-'.bin2hex(random_bytes(6));
        $this->attempts = $this->temporary.'/attempts';
        $this->events = $this->temporary.'/events';
        self::assertTrue(mkdir($this->attempts, 0700, true));
        self::assertTrue(mkdir($this->events, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach ([$this->attempts, $this->events] as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($directory.'/.*') ?: [] as $file) {
                if (!in_array(basename($file), ['.', '..'], true)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
        foreach (glob($this->temporary.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporary);
    }

    public function testMaterializesMissingOutcomeAsNotObserved(): void
    {
        $this->writeAttempt('attempt-a');

        [$exit, $stdout, $stderr] = $this->runBuilder();

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame(1, $report['attemptCount'] ?? null);
        self::assertSame(0, $report['outcomeCount'] ?? null);
        self::assertSame(1, $report['materializedNotObserved'] ?? null);
        $event = $this->decodeObject(trim((string) file_get_contents($this->temporary.'/observations.ndjson')));
        self::assertSame('not-observed', $event['status'] ?? null);
        self::assertNull($event['shadowOutputSha256'] ?? null);
    }

    public function testUsesMatchingOutcomeAndPreservesAttemptOrder(): void
    {
        $this->writeAttempt('attempt-b');
        $this->writeAttempt('attempt-a');
        $this->writeEvent('attempt-b');
        $this->writeEvent('attempt-a');

        [$exit, $stdout, $stderr] = $this->runBuilder();

        self::assertSame(0, $exit, $stderr);
        self::assertSame(0, $this->decodeObject($stdout)['materializedNotObserved'] ?? null);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->temporary.'/observations.ndjson'))));
        self::assertCount(2, $lines);
        self::assertSame('attempt-a', $this->decodeObject($lines[0])['eventId'] ?? null);
        self::assertSame('attempt-b', $this->decodeObject($lines[1])['eventId'] ?? null);
    }

    public function testRejectsOutcomeWithoutReservedAttempt(): void
    {
        $this->writeAttempt('attempt-a');
        $this->writeEvent('orphan');

        [$exit, , $stderr] = $this->runBuilder();

        self::assertSame(2, $exit);
        self::assertStringContainsString('without a reserved attempt', $stderr);
        self::assertFileDoesNotExist($this->temporary.'/observations.ndjson');
    }

    public function testRejectsOutcomeWhoseBoundInputChanged(): void
    {
        $this->writeAttempt('attempt-a');
        $event = $this->event('attempt-a');
        $event['canonicalInputSha256'] = str_repeat('f', 64);
        $this->writeJson($this->events.'/attempt-a.json', $event);

        [$exit, , $stderr] = $this->runBuilder();

        self::assertSame(2, $exit);
        self::assertStringContainsString('canonicalInputSha256', $stderr);
        self::assertFileDoesNotExist($this->temporary.'/observations.ndjson');
    }

    public function testMaterializedNotObservedFailsTheShadowGate(): void
    {
        $this->writeAttempt('attempt-a');
        [$buildExit, , $buildStderr] = $this->runBuilder();
        self::assertSame(0, $buildExit, $buildStderr);

        $stream = $this->temporary.'/observations.ndjson';
        $streamHash = hash_file('sha256', $stream);
        self::assertIsString($streamHash);
        $manifest = $this->temporary.'/manifest.json';
        $this->writeJson($manifest, [
            'schema' => 'openpairing.shadow-evidence-batch/1',
            'identity' => $this->identity(),
            'attemptedTurns' => 1,
            'eventsSha256' => $streamHash,
            'budgets' => [
                'maximumShadowElapsedMs' => 1000,
                'maximumShadowPeakMemoryBytes' => 2097152,
            ],
            'fixtureEvidence' => [],
        ]);

        [$verifyExit, $stdout, $stderr] = $this->runVerifier($manifest, $stream);

        self::assertSame(1, $verifyExit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('NO_GO', $report['status'] ?? null);
        $blockers = $report['blockers'] ?? null;
        self::assertIsArray($blockers);
        $codes = array_map(
            static fn (mixed $blocker): mixed => is_array($blocker) ? ($blocker['code'] ?? null) : null,
            $blockers,
        );
        self::assertContains('unobserved-or-infrastructure', $codes);
    }

    public function testRejectsPersonalDataBeforePublishingStream(): void
    {
        $event = $this->event('attempt-a');
        $tournament = $event['tournament'] ?? null;
        self::assertIsArray($tournament);
        $this->writeJson($this->attempts.'/attempt-a.json', [
            'schema' => 'openpairing.shadow-attempt/1',
            'eventId' => 'attempt-a',
            'reservedAtUtc' => $event['observedAtUtc'],
            'identity' => $event['identity'],
            'tournament' => [...$tournament, 'playerName' => 'must not escape'],
            'canonicalInputSha256' => $event['canonicalInputSha256'],
            'features' => $event['features'],
        ]);

        [$exit, , $stderr] = $this->runBuilder();

        self::assertSame(2, $exit);
        self::assertStringContainsString('Personal-data key is forbidden', $stderr);
        self::assertFileDoesNotExist($this->temporary.'/observations.ndjson');
    }

    private function writeAttempt(string $eventId): void
    {
        $event = $this->event($eventId);
        $this->writeJson($this->attempts.'/'.$eventId.'.json', [
            'schema' => 'openpairing.shadow-attempt/1',
            'eventId' => $eventId,
            'reservedAtUtc' => $event['observedAtUtc'],
            'identity' => $event['identity'],
            'tournament' => $event['tournament'],
            'canonicalInputSha256' => $event['canonicalInputSha256'],
            'features' => $event['features'],
        ]);
    }

    private function writeEvent(string $eventId): void
    {
        $this->writeJson($this->events.'/'.$eventId.'.json', $this->event($eventId));
    }

    /** @return array<string, mixed> */
    private function event(string $eventId): array
    {
        return [
            'schema' => 'openpairing.shadow-turn/1',
            'eventId' => $eventId,
            'observedAtUtc' => '2026-08-16T08:00:00.000000Z',
            'identity' => $this->identity(),
            'tournament' => [
                'mode' => 'fide-isolated-shadow',
                'pairingSystem' => 'fide-dutch-2026',
                'round' => 3,
                'playerCount' => 80,
                'initialColour' => 'white',
            ],
            'canonicalInputSha256' => str_repeat('a', 64),
            'primaryOutputSha256' => str_repeat('b', 64),
            'shadowOutputSha256' => str_repeat('b', 64),
            'primaryElapsedMs' => 1.0,
            'shadowElapsedMs' => 2.0,
            'shadowPeakMemoryBytes' => 1048576,
            'status' => 'exact',
            'validatorOutcome' => 'pass',
            'features' => ['even-field'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        self::assertIsInt(file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /** @return array<string, string> */
    private function identity(): array
    {
        return [
            'candidateIdentitySha256' => str_repeat('9', 64),
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

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array{int, string, string} */
    private function runBuilder(): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/build_shadow_observation_stream.php',
            $this->attempts,
            $this->events,
            $this->temporary.'/observations.ndjson',
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

    /** @return array{int, string, string} */
    private function runVerifier(string $manifest, string $stream): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/verify_shadow_evidence.php',
            $manifest,
            $stream,
            '--minimum-turns=1',
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
