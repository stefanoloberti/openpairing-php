#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$manifestPath = $argv[1] ?? $projectRoot.'/docs/schema/submission-readiness-0.9.json';

try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'openpairing.submission-readiness/1') {
        throw new RuntimeException('Submission-readiness manifest has an unsupported schema.');
    }
    if (!is_array($manifest['gates'] ?? null) || $manifest['gates'] === []) {
        throw new RuntimeException('Submission-readiness manifest has no gates.');
    }

    $seen = [];
    $closed = 0;
    $blockers = [];
    $verifiedEvidence = 0;
    foreach ($manifest['gates'] as $gate) {
        if (!is_array($gate)) {
            throw new RuntimeException('Submission-readiness manifest contains a non-object gate.');
        }
        $id = $gate['id'] ?? null;
        $status = $gate['status'] ?? null;
        $required = $gate['required'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9.-]*$/', $id)) {
            throw new RuntimeException('Submission-readiness manifest contains an invalid gate id.');
        }
        if (isset($seen[$id])) {
            throw new RuntimeException(sprintf('Duplicate submission gate: %s.', $id));
        }
        $seen[$id] = true;
        if (!in_array($status, ['closed', 'open', 'external'], true) || !is_bool($required)) {
            throw new RuntimeException(sprintf('Submission gate %s has invalid status or required flag.', $id));
        }

        $evidence = $gate['evidence'] ?? [];
        if (!is_array($evidence)) {
            throw new RuntimeException(sprintf('Submission gate %s has invalid evidence.', $id));
        }
        if ($status === 'closed' && $evidence === []) {
            throw new RuntimeException(sprintf('Closed submission gate %s has no evidence.', $id));
        }
        foreach ($evidence as $record) {
            if (!is_array($record) || !is_string($record['path'] ?? null)) {
                throw new RuntimeException(sprintf('Submission gate %s has an invalid evidence record.', $id));
            }
            $relative = str_replace('\\', '/', $record['path']);
            if ($relative === '' || str_starts_with($relative, '/') || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
                throw new RuntimeException(sprintf('Submission gate %s has an unsafe evidence path.', $id));
            }
            $absolute = $projectRoot.'/'.$relative;
            if (!is_file($absolute)) {
                throw new RuntimeException(sprintf('Submission gate %s evidence is missing: %s.', $id, $relative));
            }
            $expected = $record['sha256'] ?? null;
            if ($status === 'closed' && (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/', $expected))) {
                throw new RuntimeException(sprintf('Closed submission gate %s evidence lacks an exact SHA-256.', $id));
            }
            if (is_string($expected) && !hash_equals($expected, (string) hash_file('sha256', $absolute))) {
                throw new RuntimeException(sprintf('Submission gate %s evidence SHA-256 mismatch: %s.', $id, $relative));
            }
            ++$verifiedEvidence;
        }

        if ($status === 'closed') {
            ++$closed;
        } elseif ($required) {
            $blockers[] = ['id' => $id, 'status' => $status];
        }
    }

    $result = $blockers === [] ? 'GO' : 'NO_GO';
    echo json_encode([
        'schema' => 'openpairing.submission-readiness-verification/1',
        'status' => $result,
        'manifestSha256' => hash_file('sha256', $manifestPath),
        'target' => $manifest['target'] ?? null,
        'counts' => [
            'gates' => count($manifest['gates']),
            'closed' => $closed,
            'blocking' => count($blockers),
            'evidenceFilesVerified' => $verifiedEvidence,
        ],
        'blockers' => $blockers,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($blockers === [] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.submission-readiness-verification/1',
        'status' => 'ERROR',
        'code' => 'submission_readiness.infrastructure',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}
