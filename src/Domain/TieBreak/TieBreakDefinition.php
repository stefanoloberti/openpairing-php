<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

final readonly class TieBreakDefinition
{
    public function __construct(
        public FideTieBreakCode $code,
        public TieBreakModifier $modifier = TieBreakModifier::None,
        public bool $reverse = false,
        public bool $includeForfeits = false,
        public bool $foreBuchholz = false,
        public int $limitHalfPoints = 0,
    ) {
    }

    public function identifier(): string
    {
        $identifier = $this->code->value;
        if ($this->modifier !== TieBreakModifier::None) {
            $identifier .= '/'.match ($this->modifier) {
                TieBreakModifier::Cut1 => 'C1',
                TieBreakModifier::Cut2 => 'C2',
                TieBreakModifier::Median1 => 'M1',
                TieBreakModifier::Median2 => 'M2',
            };
        }

        if ($this->limitHalfPoints !== 0) {
            $identifier .= '/L'.($this->limitHalfPoints > 0 ? '+' : '').$this->limitHalfPoints;
        }
        if ($this->includeForfeits) {
            $identifier .= '/P';
        }
        if ($this->foreBuchholz) {
            $identifier .= '/F';
        }

        return $this->reverse ? $identifier.'-REV' : $identifier;
    }
}
