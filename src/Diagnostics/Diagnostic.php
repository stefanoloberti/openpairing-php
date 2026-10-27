<?php

declare(strict_types=1);

namespace OpenPairing\Diagnostics;

final readonly class Diagnostic
{
    /** @param array<string, bool|int|string|null> $context */
    public function __construct(
        public string $code,
        public string $message,
        public string $severity = 'info',
        public array $context = [],
    ) {
    }

    /** @return array{code: string, message: string, severity: string, context: array<string, bool|int|string|null>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
            'context' => $this->context,
        ];
    }
}

