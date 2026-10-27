<?php

declare(strict_types=1);

namespace OpenPairing\Development\Oracle;

final readonly class OracleResult
{
    /**
     * @param list<array{whitePlayerId: int, blackPlayerId: int}> $pairs
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public OracleStatus $status,
        public array $pairs,
        public ?int $byePlayerId,
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public array $metadata,
    ) {
    }
}

