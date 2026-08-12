<?php

declare(strict_types=1);

namespace OpenPairing\Diagnostics;

/** Proves which checkout Composer actually resolves before a corpus verdict. */
final class RuntimeCodeIdentity
{
    /**
     * @return array{
     *   schema: string,
     *   projectRoot: string,
     *   autoloadPsr4Roots: list<string>,
     *   expectedEnginePath: string,
     *   loadedEnginePath: string,
     *   engineVersion: string,
     *   engineSha256: string,
     *   sourceTreeSha256: string,
     *   sourceFileCount: int
     * }
     */
    public static function capture(string $projectRoot, object $loader, string $engineClass, string $engineVersion): array
    {
        $root = realpath($projectRoot);
        if ($root === false) {
            throw new \RuntimeException('Runtime identity project root does not exist.');
        }
        $expectedSource = self::normalize($root.DIRECTORY_SEPARATOR.'src');
        $expectedEngine = self::normalize($expectedSource.DIRECTORY_SEPARATOR.'Engine'.DIRECTORY_SEPARATOR.'Dutch'.DIRECTORY_SEPARATOR.'DutchPairingEngine.php');
        if (!class_exists($engineClass)) {
            throw new \RuntimeException('Runtime identity engine class does not exist: '.$engineClass);
        }
        /** @var class-string $engineClass */
        $reflection = new \ReflectionClass($engineClass);
        $loadedEngine = $reflection->getFileName();
        if ($loadedEngine === false) {
            throw new \RuntimeException('Runtime identity cannot resolve the loaded Dutch engine file.');
        }
        $loadedEngine = self::normalize($loadedEngine);

        if (!method_exists($loader, 'getPrefixesPsr4')) {
            throw new \RuntimeException('Runtime identity requires a Composer PSR-4 class loader.');
        }
        /** @var mixed $rawPrefixes */
        $rawPrefixes = $loader->getPrefixesPsr4();
        if (!is_array($rawPrefixes) || !isset($rawPrefixes['OpenPairing\\']) || !is_array($rawPrefixes['OpenPairing\\'])) {
            throw new \RuntimeException('Composer does not expose the OpenPairing PSR-4 root.');
        }
        $autoloadRoots = [];
        foreach ($rawPrefixes['OpenPairing\\'] as $autoloadRoot) {
            if (is_string($autoloadRoot)) {
                $autoloadRoots[] = self::normalize($autoloadRoot);
            }
        }
        if ($autoloadRoots === [] || $autoloadRoots[0] !== $expectedSource) {
            throw new \RuntimeException(sprintf(
                'Stale autoload detected: expected OpenPairing\\ at %s, Composer resolves %s.',
                $expectedSource,
                $autoloadRoots[0] ?? 'no path',
            ));
        }
        if ($loadedEngine !== $expectedEngine) {
            throw new \RuntimeException(sprintf(
                'Stale engine detected: expected %s, loaded %s.',
                $expectedEngine,
                $loadedEngine,
            ));
        }

        [$treeHash, $fileCount] = self::sourceTreeHash($expectedSource);
        $engineHash = hash_file('sha256', $loadedEngine);
        if (!is_string($engineHash)) {
            throw new \RuntimeException('Could not hash the loaded Dutch engine.');
        }

        return [
            'schema' => 'openpairing.runtime-code-identity/1',
            'projectRoot' => self::normalize($root),
            'autoloadPsr4Roots' => $autoloadRoots,
            'expectedEnginePath' => $expectedEngine,
            'loadedEnginePath' => $loadedEngine,
            'engineVersion' => $engineVersion,
            'engineSha256' => $engineHash,
            'sourceTreeSha256' => $treeHash,
            'sourceFileCount' => $fileCount,
        ];
    }

    /** @param array<string, mixed> $identity */
    public static function assertMatchesLock(array $identity, string $lockPath): void
    {
        if (!is_file($lockPath)) {
            return;
        }
        $raw = file_get_contents($lockPath);
        if (!is_string($raw)) {
            throw new \RuntimeException('Could not read runtime identity lock.');
        }
        $locked = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($locked)) {
            throw new \RuntimeException('Runtime identity lock is not an object.');
        }
        foreach (['engineVersion', 'engineSha256', 'sourceTreeSha256', 'sourceFileCount'] as $field) {
            if (($identity[$field] ?? null) !== ($locked[$field] ?? null)) {
                throw new \RuntimeException(sprintf(
                    'Runtime identity lock mismatch for %s: expected %s, found %s.',
                    $field,
                    json_encode($locked[$field] ?? null, JSON_THROW_ON_ERROR),
                    json_encode($identity[$field] ?? null, JSON_THROW_ON_ERROR),
                ));
            }
        }
    }

    /** @return array{string, int} */
    private static function sourceTreeHash(string $source): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = self::normalize($file->getPathname());
            }
        }
        sort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($source) + 1));
            $fileHash = hash_file('sha256', $file);
            if (!is_string($fileHash)) {
                throw new \RuntimeException('Could not hash source file '.$file.'.');
            }
            hash_update($context, $relative."\0".$fileHash."\n");
        }

        return [hash_final($context), count($files)];
    }

    private static function normalize(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new \RuntimeException('Runtime identity path does not exist: '.$path);
        }

        return str_replace('\\', '/', $resolved);
    }
}
