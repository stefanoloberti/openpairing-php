<?php

declare(strict_types=1);

namespace OpenPairing\Diagnostics;

/** Deterministic identity of the exact ordered TRF portion consumed by a run. */
final class CorpusIdentity
{
    /** @param array{absolute: string, relative: string, bytes: int, sha256: string} $file */
    public static function assertRawFileMatches(array $file, string $raw): void
    {
        $actualBytes = strlen($raw);
        $actualHash = hash('sha256', $raw);
        if ($actualBytes !== $file['bytes'] || !hash_equals($file['sha256'], $actualHash)) {
            throw new \RuntimeException(sprintf(
                'Corpus file changed during replay: %s (expected %d bytes/%s, found %d bytes/%s).',
                $file['relative'],
                $file['bytes'],
                $file['sha256'],
                $actualBytes,
                $actualHash,
            ));
        }
    }

    /**
     * @return array{
     *   files: list<array{absolute: string, relative: string, bytes: int, sha256: string}>,
     *   identity: array{schema: string, selection: string, requestedLimit: int, fileCount: int, totalBytes: int, manifestSha256: string}
     * }
     */
    public static function capture(string $root, int $limit): array
    {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) {
            throw new \RuntimeException('Corpus identity root does not exist.');
        }
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'trf') {
                continue;
            }
            $absolute = realpath($file->getPathname());
            if ($absolute === false) {
                throw new \RuntimeException('Could not resolve corpus file '.$file->getPathname().'.');
            }
            $relative = str_replace('\\', '/', ltrim(substr($absolute, strlen($resolvedRoot)), DIRECTORY_SEPARATOR));
            $paths[$relative] = $absolute;
        }
        ksort($paths, SORT_STRING);
        if ($limit > 0) {
            $paths = array_slice($paths, 0, $limit, true);
        }

        $manifest = hash_init('sha256');
        $files = [];
        $totalBytes = 0;
        foreach ($paths as $relative => $absolute) {
            $bytes = filesize($absolute);
            $sha256 = hash_file('sha256', $absolute);
            if (!is_int($bytes) || !is_string($sha256)) {
                throw new \RuntimeException('Could not attest corpus file '.$absolute.'.');
            }
            $totalBytes += $bytes;
            hash_update($manifest, $relative."\0".$bytes."\0".$sha256."\n");
            $files[] = compact('absolute', 'relative', 'bytes', 'sha256');
        }

        return [
            'files' => $files,
            'identity' => [
                'schema' => 'openpairing.corpus-identity/1',
                'selection' => 'relative-path-ordinal-v1',
                'requestedLimit' => $limit,
                'fileCount' => count($files),
                'totalBytes' => $totalBytes,
                'manifestSha256' => hash_final($manifest),
            ],
        ];
    }
}
