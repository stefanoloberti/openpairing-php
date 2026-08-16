<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class DistributionSecretScannerTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/openpairing-secret-scan-'.bin2hex(random_bytes(6));
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
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->temporary);
    }

    public function testCleanDistributionPasses(): void
    {
        file_put_contents($this->temporary.'/config.php', "<?php\nreturn ['mode' => 'fide'];\n");

        [$exit, $stdout, $stderr] = $this->runScanner();

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame('PASS', $report['status']);
        self::assertSame([], $report['findings']);
        self::assertSame(1, $report['filesScanned']);
    }

    public function testHardcodedSecretIsReportedWithoutEmittingItsValue(): void
    {
        $secret = 'actual-provider-credential-123456';
        file_put_contents($this->temporary.'/config.php', "<?php\nreturn ['api_key' => '$secret'];\n");

        [$exit, $stdout] = $this->runScanner();

        self::assertSame(1, $exit);
        self::assertStringNotContainsString($secret, $stdout);
        $report = $this->decodeObject($stdout);
        self::assertSame('REVIEW_REQUIRED', $report['status']);
        $findings = $this->findings($report);
        self::assertCount(1, $findings);
        $finding = $this->finding($findings, 0);
        self::assertSame('generic-secret-assignment', $finding['rule']);
        self::assertSame(2, $finding['line']);
        self::assertArrayHasKey('lineSha256', $finding);
        self::assertArrayNotHasKey('value', $finding);
    }

    public function testPrivateKeyHeaderAndCredentialUriAreDetected(): void
    {
        file_put_contents($this->temporary.'/private.key', "-----BEGIN PRIVATE KEY-----\n");
        file_put_contents($this->temporary.'/settings.ini', "endpoint=https://operator:long-password@example.invalid/api\n");

        [$exit, $stdout] = $this->runScanner();

        self::assertSame(1, $exit);
        $report = $this->decodeObject($stdout);
        $rules = array_column($this->findings($report), 'rule');
        self::assertContains('private-key-header', $rules);
        self::assertContains('credential-uri', $rules);
    }

    public function testExplicitPlaceholderIsNotTreatedAsCredential(): void
    {
        file_put_contents($this->temporary.'/.env.example', "API_KEY=\"change-me\"\nPASSWORD='placeholder'\n");

        [$exit, $stdout, $stderr] = $this->runScanner();

        self::assertSame(0, $exit, $stderr);
        self::assertSame('PASS', $this->decodeObject($stdout)['status']);
    }

    public function testVendorAndBinaryFilesAreNotInterpretedAsProductConfiguration(): void
    {
        self::assertTrue(mkdir($this->temporary.'/vendor'));
        file_put_contents($this->temporary.'/vendor/config.php', "<?php return ['password' => 'embedded-vendor-fixture'];");
        file_put_contents($this->temporary.'/payload.json', "{\"value\":\"a\0b\"}");

        [$exit, $stdout, $stderr] = $this->runScanner();

        self::assertSame(0, $exit, $stderr);
        $report = $this->decodeObject($stdout);
        self::assertSame(0, $report['filesScanned']);
        self::assertSame(1, $report['binaryFilesSkipped']);
    }

    /** @return array{int, string, string} */
    private function runScanner(): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open([
            PHP_BINARY,
            $root.'/tools/scan_distribution_secrets.php',
            $this->temporary,
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

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<int, mixed>
     */
    private function findings(array $report): array
    {
        $findings = $report['findings'] ?? null;
        self::assertIsArray($findings);
        self::assertTrue(array_is_list($findings));
        return $findings;
    }

    /**
     * @param array<int, mixed> $findings
     * @return array<string, mixed>
     */
    private function finding(array $findings, int $index): array
    {
        $finding = $findings[$index] ?? null;
        self::assertIsArray($finding);
        self::assertFalse(array_is_list($finding));
        $result = [];
        foreach ($finding as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException('Finding key is not a string.');
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
