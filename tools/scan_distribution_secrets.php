#!/usr/bin/env php
<?php

declare(strict_types=1);

const SECRET_SCAN_MAX_FILE_BYTES = 4_194_304;
const SECRET_SCAN_EXTENSIONS = [
    'bat', 'cmd', 'conf', 'config', 'env', 'inc', 'ini', 'js', 'json', 'key',
    'lock', 'md', 'pem', 'php', 'phtml', 'properties', 'ps1', 'sh', 'sql',
    'toml', 'ts', 'txt', 'xml', 'yaml', 'yml',
];
const SECRET_SCAN_EXCLUDED_DIRECTORIES = [
    '.git', '.idea', '.vscode', 'build', 'node_modules', 'vendor',
];

$rootArgument = $argv[1] ?? null;

try {
    if (!is_string($rootArgument)) {
        throw new RuntimeException('Usage: scan_distribution_secrets.php ROOT');
    }
    $root = realpath($rootArgument);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('Distribution root does not exist.');
    }

    $findings = [];
    $filesScanned = 0;
    $binaryFilesSkipped = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                if ($file->isLink()) {
                    return false;
                }
                return !$file->isDir() || !in_array($file->getFilename(), SECRET_SCAN_EXCLUDED_DIRECTORIES, true);
            },
        ),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || !secretScanIsCandidate($file)) {
            continue;
        }
        if ($file->getSize() > SECRET_SCAN_MAX_FILE_BYTES) {
            throw new RuntimeException('Candidate text file exceeds the scan limit: '.secretScanRelativePath($file->getPathname(), $root).'.');
        }
        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            throw new RuntimeException('Candidate text file could not be read: '.secretScanRelativePath($file->getPathname(), $root).'.');
        }
        if (str_contains($contents, "\0")) {
            ++$binaryFilesSkipped;
            continue;
        }
        ++$filesScanned;
        foreach (secretScanRules() as $rule => $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
                throw new RuntimeException('Secret scanner rule failed: '.$rule.'.');
            }
            foreach ($matches[0] ?? [] as $match) {
                $matchedText = $match[0];
                if ($rule === 'generic-secret-assignment' && secretScanIsPlaceholder($matchedText)) {
                    continue;
                }
                $line = substr_count(substr($contents, 0, $match[1]), "\n") + 1;
                $findings[] = [
                    'path' => secretScanRelativePath($file->getPathname(), $root),
                    'line' => $line,
                    'rule' => $rule,
                    'lineSha256' => hash('sha256', secretScanLineAt($contents, $line)),
                ];
            }
        }
    }
    usort($findings, static fn (array $left, array $right): int => [$left['path'], $left['line'], $left['rule']] <=> [$right['path'], $right['line'], $right['rule']]);

    $status = $findings === [] ? 'PASS' : 'REVIEW_REQUIRED';
    echo json_encode([
        'schema' => 'openpairing.distribution-secret-scan/1',
        'status' => $status,
        'filesScanned' => $filesScanned,
        'binaryFilesSkipped' => $binaryFilesSkipped,
        'findings' => $findings,
        'boundary' => [
            'secretValuesEmitted' => false,
            'credentialRotationPerformed' => false,
            'note' => 'Heuristic PASS is not proof that the distribution is secret-free; every finding requires owner review and rotation before packaging.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($findings === [] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.distribution-secret-scan/1',
        'status' => 'ERROR',
        'code' => 'distribution_secret_scan.infrastructure',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

function secretScanIsCandidate(SplFileInfo $file): bool
{
    $name = strtolower($file->getFilename());
    if ($name === '.env' || str_starts_with($name, '.env.')) {
        return true;
    }
    return in_array(strtolower($file->getExtension()), SECRET_SCAN_EXTENSIONS, true);
}

/** @return array<string, string> */
function secretScanRules(): array
{
    return [
        'private-key-header' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        'credential-uri' => '~[a-z][a-z0-9+.-]*://[^\s/:@]{2,}:[^\s/@]{6,}@~i',
        'authorization-bearer' => '/(?i)authorization[^\r\n]{0,40}bearer\s+[A-Za-z0-9._~+\/-]{16,}/',
        'known-provider-token' => '/(?:gh[pousr]_[A-Za-z0-9]{30,}|xox[baprs]-[A-Za-z0-9-]{20,}|AKIA[0-9A-Z]{16})/',
        'generic-secret-assignment' => '/(?i)["\']?(?:api[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|password|passwd|secret)["\']?\s*(?:=>|=|:)\s*["\'][^"\'\r\n]{8,}["\']/',
    ];
}

function secretScanIsPlaceholder(string $match): bool
{
    if (preg_match('/["\']([^"\'\r\n]+)["\']\s*$/D', $match, $value) !== 1) {
        return false;
    }
    return preg_match('/^(?:change[-_ ]?me|dummy|example|placeholder|sample|test(?:ing)?|x{8,}|your[-_ ].*)$/iD', $value[1]) === 1;
}

function secretScanRelativePath(string $path, string $root): string
{
    $relative = substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1);
    if ($relative === '') {
        throw new RuntimeException('Could not derive a relative scan path.');
    }
    return str_replace('\\', '/', $relative);
}

function secretScanLineAt(string $contents, int $line): string
{
    $lines = preg_split('/\R/', $contents);
    if (!is_array($lines) || !isset($lines[$line - 1])) {
        return '';
    }
    return $lines[$line - 1];
}
