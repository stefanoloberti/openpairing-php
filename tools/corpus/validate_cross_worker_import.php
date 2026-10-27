#!/usr/bin/env php
<?php

declare(strict_types=1);

use OpenPairing\Diagnostics\CorpusIdentity;

$autoload = getenv('OPENPAIRING_IMPORT_AUTOLOAD');
if (!is_string($autoload) || $autoload === '') {
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
}
if (!is_file($autoload)) {
    fwrite(STDERR, 'CROSS_WORKER_IMPORT_REJECTED Composer autoload is unavailable.'."\n");
    exit(4);
}
require $autoload;

/** @return array<string, mixed> */
function readJsonObject(string $path, string $label): array
{
    if (!is_file($path) || filesize($path) === 0) {
        throw new RuntimeException($label.' is missing or empty.');
    }
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException($label.' is not a JSON object.');
    }

    return $decoded;
}

function requireHash(string $value, string $label, int $length): string
{
    $value = strtolower($value);
    if (preg_match('/^[0-9a-f]{'.$length.'}$/D', $value) !== 1) {
        throw new RuntimeException($label.' is not a lowercase hexadecimal identity.');
    }

    return $value;
}

/** @param array<string, mixed> $report */
function canonicalSemanticHash(array $report): string
{
    unset(
        $report['root'],
        $report['elapsedSeconds'],
        $report['runtimeIdentity']['projectRoot'],
        $report['runtimeIdentity']['autoloadPsr4Roots'],
        $report['runtimeIdentity']['expectedEnginePath'],
        $report['runtimeIdentity']['loadedEnginePath'],
    );

    return hash('sha256', json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

try {
    if ($argc !== 9) {
        throw new InvalidArgumentException(
            'Usage: validate_cross_worker_import.php REPORT ATTESTATION CORPUS_ROOT SHARD EXPECTED_HOST EXPECTED_SOURCE_SHA256 EXPECTED_HARNESS_SHA256 EXPECTED_COMMIT',
        );
    }

    [, $reportPath, $attestationPath, $corpusRoot, $shard, $expectedHost, $expectedSource, $expectedHarness, $expectedCommit] = $argv;
    if (preg_match('/^s[0-9]{3}$/D', $shard) !== 1) {
        throw new RuntimeException('Unsafe shard label.');
    }
    if ($expectedHost === '' || preg_match('/^[A-Za-z0-9._-]+$/D', $expectedHost) !== 1) {
        throw new RuntimeException('Unsafe expected host.');
    }
    $expectedSource = requireHash($expectedSource, 'Expected source-tree SHA-256', 64);
    $expectedHarness = requireHash($expectedHarness, 'Expected harness SHA-256', 64);
    $expectedCommit = requireHash($expectedCommit, 'Expected campaign commit', 40);

    $report = readJsonObject($reportPath, 'Cross-worker report');
    $attestation = readJsonObject($attestationPath, 'Cross-worker attestation');
    $runtime = is_array($report['runtimeIdentity'] ?? null) ? $report['runtimeIdentity'] : [];
    $harness = is_array($report['harnessIdentity'] ?? null) ? $report['harnessIdentity'] : [];
    $corpus = is_array($report['corpusIdentity'] ?? null) ? $report['corpusIdentity'] : [];
    $counts = is_array($report['counts'] ?? null) ? $report['counts'] : [];

    if (($attestation['schema'] ?? '') !== 'openpairing.cross-worker-provenance/1'
        || ($attestation['acceptance'] ?? '') !== 'quarantine-only'
        || ($attestation['primaryAcceptor'] ?? '') !== 'doppia-3090'
        || ($attestation['shard'] ?? '') !== $shard
        || ($attestation['host'] ?? '') !== $expectedHost
        || ($attestation['campaignCommit'] ?? '') !== $expectedCommit) {
        throw new RuntimeException('Cross-worker provenance identity mismatch.');
    }
    if (($report['productionOnly'] ?? false) !== true
        || ($report['largeFieldAudit'] ?? false) !== true
        || ($runtime['sourceTreeSha256'] ?? '') !== $expectedSource
        || ($harness['sha256'] ?? '') !== $expectedHarness
        || ($runtime['arithmeticBackend'] ?? '') !== 'gmp') {
        throw new RuntimeException('Cross-worker report runtime identity/backend mismatch.');
    }
    if (($attestation['runtimeIdentity'] ?? null) !== $runtime
        || ($attestation['harnessIdentity'] ?? null) !== $harness
        || ($attestation['corpusIdentity'] ?? null) !== $corpus) {
        throw new RuntimeException('Cross-worker attestation does not reproduce the report identities.');
    }

    $reportSha256 = (string) hash_file('sha256', $reportPath);
    if (!hash_equals((string) ($attestation['reportSha256'] ?? ''), $reportSha256)) {
        throw new RuntimeException('Cross-worker raw report SHA-256 mismatch.');
    }
    $canonicalSha256 = canonicalSemanticHash($report);
    if (!hash_equals((string) ($attestation['canonicalSemanticSha256'] ?? ''), $canonicalSha256)) {
        throw new RuntimeException('Cross-worker canonical semantic SHA-256 mismatch.');
    }

    $localCorpus = CorpusIdentity::capture($corpusRoot, 0)['identity'];
    if ($localCorpus !== $corpus) {
        throw new RuntimeException('Primary corpus identity differs from the cross-worker report.');
    }

    $filesSeen = (int) ($counts['filesSeen'] ?? 0);
    $decoded = (int) ($counts['decoded'] ?? 0);
    $verified = (int) ($counts['tournamentsVerified'] ?? 0);
    $attempted = (int) ($counts['roundsAttempted'] ?? 0);
    $diagnostics = array_sum(array_map('intval', is_array($report['diagnostics'] ?? null) ? $report['diagnostics'] : []));
    $fatal = (int) ($counts['largeField.candidateNoMatching'] ?? 0)
        + (int) ($counts['roundsEngineNoSolution'] ?? 0)
        + (int) ($counts['roundsEngineResourceLimit'] ?? 0);
    if ($filesSeen < 1 || $filesSeen !== $decoded || $decoded !== $verified || $attempted < 1 || $diagnostics !== 0 || $fatal !== 0) {
        throw new RuntimeException('Cross-worker report is incomplete or contains infrastructure/fatal diagnostics.');
    }

    echo json_encode([
        'schema' => 'openpairing.cross-worker-import-validation/1',
        'validatedAt' => date(DATE_ATOM),
        'shard' => $shard,
        'sourceHost' => $expectedHost,
        'campaignCommit' => $expectedCommit,
        'sourceTreeSha256' => $expectedSource,
        'harnessSha256' => $expectedHarness,
        'arithmeticBackend' => 'gmp',
        'reportSha256' => $reportSha256,
        'attestationSha256' => hash_file('sha256', $attestationPath),
        'canonicalSemanticSha256' => $canonicalSha256,
        'corpusIdentity' => $localCorpus,
        'counts' => [
            'filesSeen' => $filesSeen,
            'tournamentsVerified' => $verified,
            'roundsAttempted' => $attempted,
        ],
        'validatorSha256' => hash_file('sha256', __FILE__),
        'sentinel' => 'PASS_QUARANTINE_VALIDATED_NOT_YET_ACCEPTED',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'CROSS_WORKER_IMPORT_REJECTED '.$error->getMessage()."\n");
    exit(4);
}
