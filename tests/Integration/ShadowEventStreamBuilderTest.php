<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ShadowEventStreamBuilderTest extends TestCase
{
    private string $temporary;
    private string $events;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-shadow-stream-'.bin2hex(random_bytes(6));
        $this->events = $this->temporary.'/events';
        self::assertTrue(mkdir($this->events, 0700, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->events.'/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->events.'/.*') ?: [] as $file) {
            if (basename($file) !== '.' && basename($file) !== '..') {
                unlink($file);
            }
        }
        foreach (glob($this->temporary.'/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                rmdir($entry);
            } else {
                unlink($entry);
            }
        }
        rmdir($this->temporary);
    }

    public function testBuildsDeterministicCanonicalStreamInFilenameOrder(): void
    {
        $this->writeEvent('event-b', [
            'z' => 2,
            'nested' => ['z' => 2, 'a' => 1],
        ]);
        $this->writeEvent('event-a', [
            'z' => 1,
            'nested' => ['z' => 4, 'a' => 3],
        ]);

        $output = $this->temporary.'/events.ndjson';
        [$exit, $stdout, $stderr] = $this->runBuilder($output);

        self::assertSame(0, $exit, $stderr);
        $expected = implode("\n", [
            '{"eventId":"event-a","nested":{"a":3,"z":4},"schema":"openpairing.shadow-turn/1","z":1}',
            '{"eventId":"event-b","nested":{"a":1,"z":2},"schema":"openpairing.shadow-turn/1","z":2}',
            '',
        ]);
        self::assertSame($expected, file_get_contents($output));
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('PASS', $report['status'] ?? null);
        self::assertSame(2, $report['eventCount'] ?? null);
        self::assertSame(hash('sha256', $expected), $report['eventsSha256'] ?? null);
        self::assertSame([], glob($this->temporary.'/*.tmp') ?: []);
    }

    public function testRefusesToOverwriteExistingStream(): void
    {
        $this->writeEvent('event-a');
        $output = $this->temporary.'/events.ndjson';
        self::assertSame(8, file_put_contents($output, "original"));

        [$exit, , $stderr] = $this->runBuilder($output);

        self::assertSame(2, $exit);
        self::assertStringContainsString('will not be overwritten', $stderr);
        self::assertSame('original', file_get_contents($output));
    }

    public function testRejectsHiddenPartialEntry(): void
    {
        $this->writeEvent('event-a');
        self::assertSame(7, file_put_contents($this->events.'/.partial', 'partial'));

        [$exit, , $stderr] = $this->runBuilder($this->temporary.'/events.ndjson');

        self::assertSame(2, $exit);
        self::assertStringContainsString('partial or unexpected entry', $stderr);
        self::assertFileDoesNotExist($this->temporary.'/events.ndjson');
    }

    public function testRejectsFilenameEventIdMismatch(): void
    {
        $bytes = json_encode([
            'schema' => 'openpairing.shadow-turn/1',
            'eventId' => 'different-event',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertIsInt(file_put_contents($this->events.'/event-a.json', $bytes));

        [$exit, , $stderr] = $this->runBuilder($this->temporary.'/events.ndjson');

        self::assertSame(2, $exit);
        self::assertStringContainsString('does not match its opaque event ID', $stderr);
        self::assertFileDoesNotExist($this->temporary.'/events.ndjson');
    }

    /** @param array<string, mixed> $extra */
    private function writeEvent(string $eventId, array $extra = []): void
    {
        $bytes = json_encode([
            'schema' => 'openpairing.shadow-turn/1',
            'eventId' => $eventId,
            ...$extra,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertIsInt(file_put_contents($this->events.'/'.$eventId.'.json', $bytes));
    }

    /** @return array{int, string, string} */
    private function runBuilder(string $output): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/build_shadow_event_stream.php',
            $this->events,
            $output,
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
