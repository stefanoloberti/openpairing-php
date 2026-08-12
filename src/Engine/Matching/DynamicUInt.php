<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Matching;

/**
 * Fixed-width unsigned arithmetic for exact Blossom edge and dual weights.
 *
 * The representation deliberately uses 30-bit limbs: adding two limbs and a
 * carry remains exact on every PHP 8.3 platform supported by this project.
 * Operations wrap modulo 2^(30 * limbCount), matching BBP DynamicUint's
 * fixed-view arithmetic. Production weight builders must reserve two guard
 * bits, as required by matching::Computer upstream.
 *
 * Semantic port of the arithmetic contract in BBP Pairings
 * src/utility/dynamicuint.h (Apache-2.0), with a PHP-specific immutable API.
 */
final readonly class DynamicUInt
{
    private const LIMB_BITS = 30;
    private const LIMB_BASE = 1 << self::LIMB_BITS;
    private const LIMB_MASK = self::LIMB_BASE - 1;

    /** @param list<int> $limbs Little-endian; validated by all factories. */
    private function __construct(private array $limbs)
    {
        if ($limbs === []) {
            throw new \InvalidArgumentException('DynamicUInt requires at least one limb.');
        }
    }

    public static function zero(int $bitWidth = 128): self
    {
        if ($bitWidth < 1) {
            throw new \InvalidArgumentException('DynamicUInt bit width must be positive.');
        }

        /** @var non-empty-list<int> $limbs */
        $limbs = array_fill(0, intdiv($bitWidth + self::LIMB_BITS - 1, self::LIMB_BITS), 0);

        return new self($limbs);
    }

    public static function fromInt(int $value, int $bitWidth = 128): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('DynamicUInt cannot be constructed from a negative integer.');
        }
        $result = self::zero($bitWidth);
        $limbs = $result->limbs;
        for ($index = 0; $index < count($limbs) && $value !== 0; ++$index) {
            $limbs[$index] = $value & self::LIMB_MASK;
            $value = intdiv($value, self::LIMB_BASE);
        }
        if ($value !== 0) {
            throw new \OverflowException('Integer does not fit the requested DynamicUInt width.');
        }

        return new self($limbs);
    }

    public function bitWidth(): int
    {
        return count($this->limbs) * self::LIMB_BITS;
    }

    public function isZero(): bool
    {
        foreach ($this->limbs as $limb) {
            if ($limb !== 0) {
                return false;
            }
        }

        return true;
    }

    public function compare(self $other): int
    {
        $this->assertSameWidth($other);
        for ($index = count($this->limbs) - 1; $index >= 0; --$index) {
            if ($this->limbs[$index] !== $other->limbs[$index]) {
                return $this->limbs[$index] <=> $other->limbs[$index];
            }
        }

        return 0;
    }

    public function add(self $other): self
    {
        $this->assertSameWidth($other);
        $result = [];
        $carry = 0;
        foreach ($this->limbs as $index => $limb) {
            $sum = $limb + $other->limbs[$index] + $carry;
            $result[] = $sum & self::LIMB_MASK;
            $carry = $sum >= self::LIMB_BASE ? 1 : 0;
        }

        return new self($result);
    }

    public function addInt(int $value): self
    {
        return $this->add(self::fromInt($value, $this->bitWidth()));
    }

    public function subtract(self $other): self
    {
        $this->assertSameWidth($other);
        $result = [];
        $borrow = 0;
        foreach ($this->limbs as $index => $limb) {
            $difference = $limb - $other->limbs[$index] - $borrow;
            if ($difference < 0) {
                $difference += self::LIMB_BASE;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result[] = $difference;
        }

        return new self($result);
    }

    public function shiftLeft(int $bits): self
    {
        if ($bits < 0) {
            return $this->shiftRight(-$bits);
        }
        $limbShift = intdiv($bits, self::LIMB_BITS);
        $bitShift = $bits % self::LIMB_BITS;
        $count = count($this->limbs);
        $result = array_fill(0, $count, 0);
        for ($source = 0; $source < $count; ++$source) {
            $target = $source + $limbShift;
            if ($target >= $count) {
                break;
            }
            $value = $this->limbs[$source] << $bitShift;
            $result[$target] = ($result[$target] | ($value & self::LIMB_MASK)) & self::LIMB_MASK;
            if ($bitShift !== 0 && $target + 1 < $count) {
                $result[$target + 1] = ($result[$target + 1] | intdiv($value, self::LIMB_BASE)) & self::LIMB_MASK;
            }
        }

        return new self(array_values($result));
    }

    public function shiftRight(int $bits): self
    {
        if ($bits < 0) {
            return $this->shiftLeft(-$bits);
        }
        $limbShift = intdiv($bits, self::LIMB_BITS);
        $bitShift = $bits % self::LIMB_BITS;
        $count = count($this->limbs);
        $result = array_fill(0, $count, 0);
        for ($target = 0; $target < $count; ++$target) {
            $source = $target + $limbShift;
            if ($source >= $count) {
                break;
            }
            $value = intdiv($this->limbs[$source], 1 << $bitShift);
            if ($bitShift !== 0 && $source + 1 < $count) {
                $value |= ($this->limbs[$source + 1] & ((1 << $bitShift) - 1)) << (self::LIMB_BITS - $bitShift);
            }
            $result[$target] = $value & self::LIMB_MASK;
        }

        return new self(array_values($result));
    }

    public function withLowBits(int $value): self
    {
        if ($value < 0 || $value > self::LIMB_MASK) {
            throw new \InvalidArgumentException('Low-bit value must fit one DynamicUInt limb.');
        }
        $limbs = $this->limbs;
        $limbs[0] |= $value;

        return new self($limbs);
    }

    public function lowBits(int $mask): int
    {
        if ($mask < 0 || $mask > self::LIMB_MASK) {
            throw new \InvalidArgumentException('Low-bit mask must fit one DynamicUInt limb.');
        }

        return $this->limbs[0] & $mask;
    }

    public function toHex(): string
    {
        $highestNibble = intdiv($this->bitWidth() + 3, 4) - 1;
        $hex = '';
        $started = false;
        for ($nibble = $highestNibble; $nibble >= 0; --$nibble) {
            $value = 0;
            for ($bit = 0; $bit < 4; ++$bit) {
                $bitIndex = $nibble * 4 + $bit;
                if ($bitIndex >= $this->bitWidth()) {
                    continue;
                }
                $limb = intdiv($bitIndex, self::LIMB_BITS);
                $offset = $bitIndex % self::LIMB_BITS;
                $value |= (($this->limbs[$limb] >> $offset) & 1) << $bit;
            }
            if ($value !== 0 || $started) {
                $hex .= dechex($value);
                $started = true;
            }
        }

        return $hex === '' ? '0' : $hex;
    }

    private function assertSameWidth(self $other): void
    {
        if (count($this->limbs) !== count($other->limbs)) {
            throw new \InvalidArgumentException('DynamicUInt operands must have equal width.');
        }
    }
}
