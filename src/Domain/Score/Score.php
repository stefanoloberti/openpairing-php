<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Score;

use InvalidArgumentException;

final readonly class Score
{
    public function __construct(
        public int $units,
        public int $scale,
    ) {
        if ($scale < 1 || $scale > 1_000_000) {
            throw new InvalidArgumentException('Score scale must be between 1 and 1,000,000.');
        }

        if (abs($units) > 1_000_000_000) {
            throw new InvalidArgumentException('Score units exceed the supported range.');
        }
    }

    public static function zero(): self
    {
        return new self(0, 1);
    }

    public static function fromDecimal(string $value): self
    {
        $value = trim($value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid exact decimal score.');
        }

        $fraction = $matches[3] ?? '';
        $scale = 10 ** strlen($fraction);
        $units = ((int) $matches[2] * $scale) + ($fraction === '' ? 0 : (int) $fraction);
        if ($matches[1] === '-') {
            $units *= -1;
        }

        return new self($units, $scale);
    }

    public function toDecimal(int $fractionDigits = 1): string
    {
        if ($fractionDigits < 0 || $fractionDigits > 6) {
            throw new InvalidArgumentException('Decimal precision must be between 0 and 6.');
        }

        $factor = 10 ** $fractionDigits;
        $scaled = $this->units * $factor;
        if ($scaled % $this->scale !== 0) {
            throw new InvalidArgumentException('Score cannot be represented exactly at the requested precision.');
        }

        $decimalUnits = intdiv($scaled, $this->scale);
        $sign = $decimalUnits < 0 ? '-' : '';
        $absolute = abs($decimalUnits);
        if ($fractionDigits === 0) {
            return $sign.(string) $absolute;
        }

        $digits = str_pad((string) $absolute, $fractionDigits + 1, '0', STR_PAD_LEFT);
        return $sign.substr($digits, 0, -$fractionDigits).'.'.substr($digits, -$fractionDigits);
    }

    public function compare(self $other): int
    {
        return ($this->units * $other->scale) <=> ($other->units * $this->scale);
    }

    public function add(self $other): self
    {
        $commonDivisor = self::greatestCommonDivisor($this->scale, $other->scale);
        $leftMultiplier = intdiv($other->scale, $commonDivisor);
        $rightMultiplier = intdiv($this->scale, $commonDivisor);
        $units = ($this->units * $leftMultiplier) + ($other->units * $rightMultiplier);
        $scale = $this->scale * $leftMultiplier;

        $reduction = self::greatestCommonDivisor(abs($units), $scale);

        return new self(intdiv($units, $reduction), intdiv($scale, $reduction));
    }

    public function subtract(self $other): self
    {
        return $this->add(new self(-$other->units, $other->scale));
    }

    public function multiply(self $other): self
    {
        $leftUnits = $this->units;
        $leftScale = $this->scale;
        $rightUnits = $other->units;
        $rightScale = $other->scale;
        $crossLeft = self::greatestCommonDivisor(abs($leftUnits), $rightScale);
        $crossRight = self::greatestCommonDivisor(abs($rightUnits), $leftScale);
        $leftUnits = intdiv($leftUnits, $crossLeft);
        $rightScale = intdiv($rightScale, $crossLeft);
        $rightUnits = intdiv($rightUnits, $crossRight);
        $leftScale = intdiv($leftScale, $crossRight);
        if ($leftUnits !== 0 && abs($rightUnits) > intdiv(PHP_INT_MAX, abs($leftUnits))) {
            throw new InvalidArgumentException('Score multiplication overflows the integer range.');
        }

        return new self($leftUnits * $rightUnits, $leftScale * $rightScale);
    }

    /** @return array{units: int, scale: int} */
    public function toArray(): array
    {
        return ['units' => $this->units, 'scale' => $this->scale];
    }

    private static function greatestCommonDivisor(int $left, int $right): int
    {
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return max(1, abs($left));
    }
}
