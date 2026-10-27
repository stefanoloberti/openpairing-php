<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Pairing;

use OpenPairing\Diagnostics\Diagnostic;

final readonly class PairingResult
{
    /**
     * @param list<PairingTable> $tables
     * @param list<int> $unpairedPlayerIds
     * @param list<Diagnostic> $diagnostics
     * @param list<Diagnostic> $violations
     * @param list<int> $qualityVector
     * @param list<array{step: int, code: string, detail: string}> $trace
     * @param list<FloatAssignment> $floatAssignments
     */
    public function __construct(
        public array $tables,
        public ?int $byePlayerId,
        public array $unpairedPlayerIds,
        public array $diagnostics,
        public array $violations,
        public array $qualityVector,
        public array $trace,
        public string $algorithmVersion,
        public array $floatAssignments = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'algorithmVersion' => $this->algorithmVersion,
            'tables' => array_map(
                static fn (PairingTable $table): array => $table->toArray(),
                $this->tables,
            ),
            'byePlayerId' => $this->byePlayerId,
            'unpairedPlayerIds' => $this->unpairedPlayerIds,
            'diagnostics' => array_map(
                static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
            'violations' => array_map(
                static fn (Diagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->violations,
            ),
            'qualityVector' => $this->qualityVector,
            'trace' => $this->trace,
            'floatAssignments' => array_map(
                static fn (FloatAssignment $assignment): array => $assignment->toArray(),
                $this->floatAssignments,
            ),
        ];
    }
}
