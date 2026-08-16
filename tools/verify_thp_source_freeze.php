#!/usr/bin/env php
<?php

declare(strict_types=1);

$productRootArgument = $argv[1] ?? null;
$migrationArguments = array_slice($argv, 2);

try {
    if (!is_string($productRootArgument) || $migrationArguments === []) {
        throw new RuntimeException('Usage: verify_thp_source_freeze.php PRODUCT_ROOT MIGRATION_PATH [MIGRATION_PATH ...]');
    }
    $root = realpath($productRootArgument);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('Product root does not exist.');
    }

    $topLevel = trim(thpFreezeRun(['git', '-C', $root, 'rev-parse', '--show-toplevel'], 'Git root'));
    $resolvedTopLevel = realpath($topLevel);
    if ($resolvedTopLevel === false || thpFreezeNormalizeAbsolute($resolvedTopLevel) !== thpFreezeNormalizeAbsolute($root)) {
        throw new RuntimeException('Product root must be the exact root of one Git worktree.');
    }
    $head = trim(thpFreezeRun(['git', '-C', $root, 'rev-parse', 'HEAD'], 'Git HEAD'));
    if (preg_match('/^[a-f0-9]{40}$/D', $head) !== 1) {
        throw new RuntimeException('Product HEAD is not a full Git commit.');
    }
    thpFreezeRequireClean($root);

    $trackedOutput = thpFreezeRun(['git', '-C', $root, 'ls-files', '-z'], 'tracked-file inventory');
    $trackedPaths = array_values(array_filter(explode("\0", $trackedOutput), static fn (string $path): bool => $path !== ''));
    if ($trackedPaths === []) {
        throw new RuntimeException('Product worktree has no tracked files.');
    }
    sort($trackedPaths, SORT_STRING);
    $sourceRecords = [];
    foreach ($trackedPaths as $relative) {
        $relative = thpFreezeRequireRelativePath($relative, 'tracked file');
        $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_link($absolute) || !is_file($absolute)) {
            throw new RuntimeException('Tracked source entry is missing or is a symbolic link: '.$relative.'.');
        }
        $bytes = filesize($absolute);
        $sha256 = hash_file('sha256', $absolute);
        if (!is_int($bytes) || !is_string($sha256)) {
            throw new RuntimeException('Could not attest tracked source file: '.$relative.'.');
        }
        $sourceRecords[] = ['path' => $relative, 'bytes' => $bytes, 'sha256' => $sha256];
    }

    $migrationPaths = [];
    $migrationFiles = [];
    foreach ($migrationArguments as $index => $argument) {
        $relative = thpFreezeRequireRelativePath($argument, sprintf('migration path %d', $index));
        if (isset($migrationPaths[$relative])) {
            throw new RuntimeException('Duplicate migration path: '.$relative.'.');
        }
        $migrationPaths[$relative] = true;
        $prefix = rtrim($relative, '/').'/';
        $matches = [];
        foreach ($sourceRecords as $record) {
            if ($record['path'] === $relative || str_starts_with($record['path'], $prefix)) {
                $matches[] = $record;
            }
        }
        if ($matches === []) {
            throw new RuntimeException('Migration path has no tracked files: '.$relative.'.');
        }
        foreach ($matches as $record) {
            $migrationFiles[$record['path']] = $record;
        }
    }
    ksort($migrationFiles, SORT_STRING);

    [$secretExit, $secretStdout, $secretStderr] = thpFreezeExecute([
        PHP_BINARY,
        __DIR__.'/scan_distribution_secrets.php',
        $root,
    ]);
    if ($secretExit === 2) {
        throw new RuntimeException('Distribution secret scan failed: '.trim($secretStderr));
    }
    $secretReport = thpFreezeDecodeObject($secretStdout, 'distribution secret scan report');
    $secretFindings = $secretReport['findings'] ?? null;
    if (!is_array($secretFindings) || !array_is_list($secretFindings)) {
        throw new RuntimeException('Distribution secret scan returned an invalid finding list.');
    }
    if ($secretExit !== 0 || ($secretReport['status'] ?? null) !== 'PASS' || $secretFindings !== []) {
        throw new RuntimeException(sprintf('Distribution secret scan requires private review of %d finding(s).', count($secretFindings)));
    }

    thpFreezeRequireClean($root);
    $headAfter = trim(thpFreezeRun(['git', '-C', $root, 'rev-parse', 'HEAD'], 'post-scan Git HEAD'));
    if ($headAfter !== $head) {
        throw new RuntimeException('Product HEAD changed during source-freeze verification.');
    }

    echo json_encode([
        'schema' => 'openpairing.thp-source-freeze-verification/1',
        'status' => 'PASS',
        'sourceCommit' => $head,
        'trackedFileCount' => count($sourceRecords),
        'sourceManifestSha256' => thpFreezeRecordsHash($sourceRecords),
        'migrationPaths' => array_keys($migrationPaths),
        'migrationFileCount' => count($migrationFiles),
        'migrationManifestSha256' => thpFreezeRecordsHash(array_values($migrationFiles)),
        'secretScan' => [
            'status' => 'PASS',
            'filesScanned' => $secretReport['filesScanned'] ?? null,
            'binaryFilesSkipped' => $secretReport['binaryFilesSkipped'] ?? null,
            'findings' => 0,
            'secretValuesEmitted' => false,
        ],
        'boundary' => [
            'sourceModified' => false,
            'databaseInspected' => false,
            'databaseMutationPerformed' => false,
            'productionMutationPerformed' => false,
            'candidateIdentityCreated' => false,
            'note' => 'PASS attests one clean source and migration snapshot. It does not build, deploy or accept a THP candidate.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-source-freeze-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_source_freeze.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

function thpFreezeRequireClean(string $root): void
{
    $status = thpFreezeRun(['git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all'], 'Git status');
    if ($status !== '') {
        throw new RuntimeException('Product worktree is not clean, including untracked files.');
    }
}

function thpFreezeNormalizeAbsolute(string $path): string
{
    $normalized = str_replace('\\', '/', rtrim($path, '/\\'));
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function thpFreezeRequireRelativePath(string $path, string $label): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/D', $path) === 1) {
        throw new RuntimeException($label.' must be a portable relative path.');
    }
    $segments = explode('/', $path);
    if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        throw new RuntimeException($label.' contains an unsafe path segment.');
    }
    return implode('/', $segments);
}

/** @param list<array{path: string, bytes: int, sha256: string}> $records */
function thpFreezeRecordsHash(array $records): string
{
    return hash('sha256', json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/** @param list<string> $command */
function thpFreezeRun(array $command, string $label): string
{
    [$exit, $stdout, $stderr] = thpFreezeExecute($command);
    if ($exit !== 0) {
        throw new RuntimeException($label.' failed: '.trim($stderr));
    }
    return $stdout;
}

/**
 * @param list<string> $command
 * @return array{int, string, string}
 */
function thpFreezeExecute(array $command): array
{
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start source-freeze subprocess.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
}

/** @return array<string, mixed> */
function thpFreezeDecodeObject(string $json, string $label): array
{
    $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException($label.' must be an object.');
    }
    /** @var array<string, mixed> $decoded */
    return $decoded;
}
