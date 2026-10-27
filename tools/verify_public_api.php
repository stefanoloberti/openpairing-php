#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$manifestPath = dirname(__DIR__).'/docs/schema/public-api-0.9.json';
try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !is_array($manifest['methods'] ?? null)) {
        throw new RuntimeException('Public API manifest is not a structured object.');
    }
    $actual = [];
    foreach ($manifest['methods'] as $expectedMethod) {
        if (!is_array($expectedMethod) || !is_string($expectedMethod['method'] ?? null)) {
            throw new RuntimeException('Public API manifest contains an invalid method record.');
        }
        [$class, $method] = explode('::', $expectedMethod['method'], 2) + [null, null];
        if (!is_string($class) || !is_string($method) || $class === '' || $method === '') {
            throw new RuntimeException('Public API manifest contains an invalid method name.');
        }
        $reflection = new ReflectionMethod($class, $method);
        if (!$reflection->isPublic()) {
            throw new RuntimeException(sprintf('Supported API method is not public: %s.', $expectedMethod['method']));
        }
        $params = [];
        foreach ($reflection->getParameters() as $parameter) {
            $default = null;
            if ($parameter->isDefaultValueAvailable()) {
                $default = $parameter->getDefaultValue();
                if ($default instanceof BackedEnum) {
                    $default = $default->value;
                }
            }
            $params[] = [
                'name' => $parameter->getName(),
                'type' => (string) $parameter->getType(),
                'optional' => $parameter->isOptional(),
                'default' => $default,
            ];
        }
        $actual[] = [
            'method' => $expectedMethod['method'],
            'return' => (string) $reflection->getReturnType(),
            'params' => $params,
        ];
    }
    if ($actual !== $manifest['methods']) {
        fwrite(STDERR, json_encode([
            'status' => 'FAIL',
            'expected' => $manifest['methods'],
            'actual' => $actual,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        exit(1);
    }
    $actualJson = json_encode($actual, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    echo json_encode([
        'schema' => 'openpairing.public-api-verification/1',
        'status' => 'PASS',
        'packageVersion' => $manifest['packageVersion'] ?? null,
        'methodCount' => count($actual),
        'manifestSha256' => hash_file('sha256', $manifestPath),
        'normalizedApiSha256' => hash('sha256', $actualJson),
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'ERROR',
        'code' => 'public_api.infrastructure',
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(2);
}
