#!/usr/bin/env php
<?php

declare(strict_types=1);

const THP_IDENTITY_REQUIRED_ROLES = [
    'product-installer',
    'openpairing-runtime',
    'ptc',
    'rtg',
];

$manifestPath = $argv[1] ?? null;
$artifactRoot = $argv[2] ?? null;

try {
    if (!is_string($manifestPath) || !is_string($artifactRoot)) {
        throw new RuntimeException('Usage: verify_thp_candidate_identity.php MANIFEST ARTIFACT_ROOT');
    }
    $manifest = thpIdentityDecodeObject($manifestPath, 'candidate identity manifest');
    thpIdentityRequireExactKeys($manifest, [
        'schema', 'candidateName', 'product', 'package', 'runtime', 'standards', 'artifacts',
    ], 'manifest');
    if (($manifest['schema'] ?? null) !== 'openpairing.thp-candidate-identity/1') {
        throw new RuntimeException('Candidate identity manifest has an unsupported schema.');
    }
    thpIdentityRequireString($manifest['candidateName'] ?? null, 'candidateName');

    $product = thpIdentityRequireObject($manifest['product'] ?? null, 'product');
    thpIdentityRequireExactKeys($product, ['name', 'version', 'sourceCommit', 'fideMode'], 'product');
    foreach (['name', 'version', 'fideMode'] as $field) {
        thpIdentityRequireString($product[$field] ?? null, 'product.'.$field);
    }
    thpIdentityRequirePattern($product['sourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'product.sourceCommit', 'a lowercase 40-character Git commit');

    $package = thpIdentityRequireObject($manifest['package'] ?? null, 'package');
    thpIdentityRequireExactKeys($package, [
        'name', 'version', 'sourceCommit', 'sourceTreeSha256', 'engineVersion', 'engineSha256',
    ], 'package');
    foreach (['name', 'version', 'engineVersion'] as $field) {
        thpIdentityRequireString($package[$field] ?? null, 'package.'.$field);
    }
    thpIdentityRequirePattern($package['sourceCommit'] ?? null, '/^[a-f0-9]{40}$/D', 'package.sourceCommit', 'a lowercase 40-character Git commit');
    foreach (['sourceTreeSha256', 'engineSha256'] as $field) {
        thpIdentityRequireSha256($package[$field] ?? null, 'package.'.$field);
    }

    $runtime = thpIdentityRequireObject($manifest['runtime'] ?? null, 'runtime');
    thpIdentityRequireExactKeys($runtime, ['osProfile', 'phpVersion', 'arithmeticBackend'], 'runtime');
    thpIdentityRequireString($runtime['osProfile'] ?? null, 'runtime.osProfile');
    thpIdentityRequirePattern($runtime['phpVersion'] ?? null, '/^[0-9]+\\.[0-9]+\\.[0-9]+(?:[-+._A-Za-z0-9]*)?$/D', 'runtime.phpVersion', 'a complete PHP version');
    if (!in_array($runtime['arithmeticBackend'] ?? null, ['gmp', 'limbs-30'], true)) {
        throw new RuntimeException('runtime.arithmeticBackend is unsupported.');
    }

    $standards = thpIdentityRequireObject($manifest['standards'] ?? null, 'standards');
    thpIdentityRequireExactKeys($standards, ['pairingSystems', 'trfVersions', 'schemaVersions'], 'standards');
    foreach (['pairingSystems', 'trfVersions', 'schemaVersions'] as $field) {
        thpIdentityRequireUniqueStrings($standards[$field] ?? null, 'standards.'.$field);
    }

    $root = realpath($artifactRoot);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('Artifact root does not exist.');
    }
    $artifacts = $manifest['artifacts'] ?? null;
    if (!is_array($artifacts) || $artifacts === []) {
        throw new RuntimeException('artifacts must be a non-empty array.');
    }
    $roles = [];
    $paths = [];
    $verified = [];
    foreach ($artifacts as $index => $artifact) {
        $label = sprintf('artifacts[%d]', $index);
        $artifact = thpIdentityRequireObject($artifact, $label);
        thpIdentityRequireExactKeys($artifact, ['role', 'path', 'sha256'], $label);
        $role = thpIdentityRequireString($artifact['role'] ?? null, $label.'.role');
        if (!in_array($role, THP_IDENTITY_REQUIRED_ROLES, true)) {
            throw new RuntimeException($label.'.role is unsupported.');
        }
        if (isset($roles[$role])) {
            throw new RuntimeException('Duplicate artifact role: '.$role.'.');
        }
        $roles[$role] = true;
        $relative = thpIdentityRequireRelativePath($artifact['path'] ?? null, $label.'.path');
        if (isset($paths[$relative])) {
            throw new RuntimeException('Duplicate artifact path: '.$relative.'.');
        }
        $paths[$relative] = true;
        $expectedHash = thpIdentityRequireSha256($artifact['sha256'] ?? null, $label.'.sha256');
        $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($absolute)) {
            throw new RuntimeException('Candidate artifact does not exist: '.$relative.'.');
        }
        $resolved = realpath($absolute);
        if ($resolved === false || !thpIdentityPathWithin($resolved, $root)) {
            throw new RuntimeException('Candidate artifact escapes the artifact root: '.$relative.'.');
        }
        $actualHash = (string) hash_file('sha256', $resolved);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('Candidate artifact SHA-256 mismatch: '.$relative.'.');
        }
        $verified[$role] = ['path' => $relative, 'sha256' => $actualHash];
    }
    $missingRoles = array_values(array_diff(THP_IDENTITY_REQUIRED_ROLES, array_keys($roles)));
    if ($missingRoles !== []) {
        throw new RuntimeException('Candidate identity lacks required artifact roles: '.implode(', ', $missingRoles).'.');
    }
    if (count($roles) !== count(THP_IDENTITY_REQUIRED_ROLES)) {
        throw new RuntimeException('Candidate identity must contain exactly the required artifact roles.');
    }
    ksort($verified, SORT_STRING);
    $canonical = thpIdentityCanonicalCandidate($manifest);
    echo json_encode([
        'schema' => 'openpairing.thp-candidate-identity-verification/1',
        'status' => 'PASS',
        'candidateName' => $manifest['candidateName'],
        'candidateIdentitySha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'manifestSha256' => hash_file('sha256', $manifestPath),
        'verifiedArtifacts' => $verified,
        'boundary' => [
            'submissionReadyDeclared' => false,
            'note' => 'Identity verification does not close product, evidence or TEC acceptance gates.',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'schema' => 'openpairing.thp-candidate-identity-verification/1',
        'status' => 'ERROR',
        'code' => 'thp_candidate_identity.invalid',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}

/** @return array<string, mixed> */
function thpIdentityDecodeObject(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label.' does not exist.');
    }
    $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    return thpIdentityRequireObject($decoded, $label);
}

/** @return array<string, mixed> */
function thpIdentityRequireObject(mixed $value, string $label): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException($label.' must be an object.');
    }
    /** @var array<string, mixed> $value */
    return $value;
}

/**
 * @param array<string, mixed> $value
 * @param list<string>         $keys
 */
function thpIdentityRequireExactKeys(array $value, array $keys, string $label): void
{
    $missing = array_values(array_diff($keys, array_keys($value)));
    if ($missing !== []) {
        throw new RuntimeException($label.' lacks required fields: '.implode(', ', $missing).'.');
    }
    $unexpected = array_values(array_diff(array_keys($value), $keys));
    if ($unexpected !== []) {
        throw new RuntimeException($label.' contains unexpected fields: '.implode(', ', $unexpected).'.');
    }
}

function thpIdentityRequireString(mixed $value, string $label): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException($label.' must be a non-empty string.');
    }
    return $value;
}

function thpIdentityRequirePattern(mixed $value, string $pattern, string $label, string $description): string
{
    $value = thpIdentityRequireString($value, $label);
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException($label.' must be '.$description.'.');
    }
    return $value;
}

function thpIdentityRequireSha256(mixed $value, string $label): string
{
    return thpIdentityRequirePattern($value, '/^[a-f0-9]{64}$/D', $label, 'a lowercase SHA-256');
}

function thpIdentityRequireRelativePath(mixed $value, string $label): string
{
    $path = thpIdentityRequireString($value, $label);
    if (str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/D', $path) === 1) {
        throw new RuntimeException($label.' must be a portable relative path.');
    }
    $segments = explode('/', $path);
    if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
        throw new RuntimeException($label.' contains an unsafe path segment.');
    }
    return $path;
}

function thpIdentityPathWithin(string $path, string $root): bool
{
    $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    return str_starts_with($path, $prefix);
}

function thpIdentityRequireUniqueStrings(mixed $value, string $label): void
{
    if (!is_array($value) || $value === [] || !array_is_list($value)) {
        throw new RuntimeException($label.' must be a non-empty list.');
    }
    $seen = [];
    foreach ($value as $index => $item) {
        $item = thpIdentityRequireString($item, sprintf('%s[%d]', $label, $index));
        if (isset($seen[$item])) {
            throw new RuntimeException($label.' contains a duplicate value.');
        }
        $seen[$item] = true;
    }
}

function thpIdentityCanonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('thpIdentityCanonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = thpIdentityCanonicalize($item);
    }
    return $value;
}

/**
 * Lists representing sets are sorted so harmless JSON ordering does not create
 * a second candidate identity.
 *
 * @param array<string, mixed> $manifest
 * @return array<string, mixed>
 */
function thpIdentityCanonicalCandidate(array $manifest): array
{
    $canonical = thpIdentityCanonicalize($manifest);
    if (!is_array($canonical) || array_is_list($canonical)) {
        throw new RuntimeException('Canonical candidate identity is not an object.');
    }
    $standards = $canonical['standards'] ?? null;
    if (is_array($standards) && !array_is_list($standards)) {
        foreach (['pairingSystems', 'trfVersions', 'schemaVersions'] as $field) {
            $values = $standards[$field] ?? null;
            if (is_array($values) && array_is_list($values)) {
                $sortable = [];
                foreach ($values as $value) {
                    if (!is_string($value)) {
                        throw new RuntimeException('Canonical standard value is not a string.');
                    }
                    $sortable[] = $value;
                }
                sort($sortable, SORT_STRING);
                $standards[$field] = $sortable;
            }
        }
        $canonical['standards'] = $standards;
    }
    $artifacts = $canonical['artifacts'] ?? null;
    if (is_array($artifacts) && array_is_list($artifacts)) {
        usort($artifacts, static function (mixed $left, mixed $right): int {
            $leftRole = is_array($left) && is_string($left['role'] ?? null) ? $left['role'] : '';
            $rightRole = is_array($right) && is_string($right['role'] ?? null) ? $right['role'] : '';
            return $leftRole <=> $rightRole;
        });
        $canonical['artifacts'] = $artifacts;
    }
    /** @var array<string, mixed> $canonical */
    return $canonical;
}
