<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ThpSourceFreezeVerifierTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-thp-freeze-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary));
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporary, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                throw new \RuntimeException('Unexpected temporary directory entry.');
            }
            if (!$entry->isDir()) {
                chmod($entry->getPathname(), 0666);
                unlink($entry->getPathname());
                continue;
            }
            rmdir($entry->getPathname());
        }
        rmdir($this->temporary);
    }

    public function testCleanTrackedSnapshotPassesDeterministically(): void
    {
        $this->initializeRepository();

        [$firstExit, $firstStdout, $firstStderr] = $this->runVerifier('database');
        [$secondExit, $secondStdout, $secondStderr] = $this->runVerifier('database');

        self::assertSame(0, $firstExit, $firstStderr);
        self::assertSame(0, $secondExit, $secondStderr);
        $first = $this->decodeObject($firstStdout);
        $second = $this->decodeObject($secondStdout);
        self::assertSame('PASS', $first['status']);
        self::assertSame(3, $first['trackedFileCount']);
        self::assertSame(1, $first['migrationFileCount']);
        self::assertSame($first['sourceManifestSha256'], $second['sourceManifestSha256']);
        self::assertSame($first['migrationManifestSha256'], $second['migrationManifestSha256']);
        $boundary = $this->object($first['boundary'] ?? null);
        self::assertFalse($boundary['sourceModified']);
        self::assertFalse($boundary['databaseInspected']);
        self::assertFalse($boundary['productionMutationPerformed']);
        self::assertFalse($boundary['candidateIdentityCreated']);
    }

    public function testDirtyTrackedFileFailsClosed(): void
    {
        $this->initializeRepository();
        file_put_contents($this->temporary.'/app.php', "<?php\n// dirty\n", FILE_APPEND);

        [$exit, , $stderr] = $this->runVerifier('database');

        self::assertSame(2, $exit);
        self::assertStringContainsString('worktree is not clean', $stderr);
    }

    public function testUntrackedFileFailsClosed(): void
    {
        $this->initializeRepository();
        file_put_contents($this->temporary.'/unexpected.txt', "not attested\n");

        [$exit, , $stderr] = $this->runVerifier('database');

        self::assertSame(2, $exit);
        self::assertStringContainsString('worktree is not clean', $stderr);
    }

    public function testCommittedSecretFailsWithoutEmittingValue(): void
    {
        $credential = 'actual-'.'provider-credential-123456';
        $secretKey = 'api_'.'key';
        $this->initializeRepository(['config.php' => "<?php\nreturn ['$secretKey' => '$credential'];\n"]);

        [$exit, $stdout, $stderr] = $this->runVerifier('database');

        self::assertSame(2, $exit);
        self::assertStringNotContainsString($credential, $stdout.$stderr);
        self::assertStringContainsString('private review of 1 finding', $stderr);
    }

    public function testMigrationPathWithoutTrackedFilesFailsClosed(): void
    {
        $this->initializeRepository();
        self::assertTrue(mkdir($this->temporary.'/ignored'));
        file_put_contents($this->temporary.'/ignored/001.sql', "SELECT 1;\n");

        [$exit, , $stderr] = $this->runVerifier('ignored');

        self::assertSame(2, $exit);
        self::assertStringContainsString('Migration path has no tracked files', $stderr);
    }

    public function testNonGitDirectoryFailsClosed(): void
    {
        file_put_contents($this->temporary.'/app.php', "<?php\n");

        [$exit, , $stderr] = $this->runVerifier('database');

        self::assertSame(2, $exit);
        self::assertStringContainsString('Git root failed', $stderr);
    }

    /** @param array<string, string> $additionalFiles */
    private function initializeRepository(array $additionalFiles = []): void
    {
        self::assertTrue(mkdir($this->temporary.'/database'));
        file_put_contents($this->temporary.'/.gitignore', "/ignored/\n");
        file_put_contents($this->temporary.'/app.php', "<?php\ndeclare(strict_types=1);\n");
        file_put_contents($this->temporary.'/database/001.sql', "CREATE TABLE pairing_jobs (id INTEGER);\n");
        foreach ($additionalFiles as $path => $content) {
            file_put_contents($this->temporary.'/'.$path, $content);
        }
        $this->git(['init', '--quiet']);
        $this->git(['config', 'user.email', 'tests@example.invalid']);
        $this->git(['config', 'user.name', 'OpenPairing tests']);
        $this->git(['add', '--all']);
        $this->git(['commit', '--quiet', '-m', 'fixture']);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        [$exit, , $stderr] = $this->execute(array_merge(['git', '-C', $this->temporary], $arguments));
        self::assertSame(0, $exit, $stderr);
    }

    /** @return array{int, string, string} */
    private function runVerifier(string $migrationPath): array
    {
        $root = dirname(__DIR__, 2);
        return $this->execute([
            PHP_BINARY,
            $root.'/tools/verify_thp_source_freeze.php',
            $this->temporary,
            $migrationPath,
        ], $root);
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private function execute(array $command, ?string $workingDirectory = null): array
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory ?? $this->temporary);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
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

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('Object key is not a string.');
            }
            $result[$key] = $item;
        }
        return $result;
    }
}
