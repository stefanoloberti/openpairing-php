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

    /**
     * @param list<int> $limbs Little-endian; validated by all factories.
     * @param \GMP|null $native GMP value when the native backend is selected.
     * @param \GMP|null $nativeMask GMP value equal to 2^bitWidth - 1.
     */
    private function __construct(
        private array $limbs,
        private ?\GMP $native = null,
        private ?\GMP $nativeMask = null,
        private int $nativeBitWidth = 0,
    )
    {
        if (($limbs === []) === ($native === null)) {
            throw new \InvalidArgumentException('DynamicUInt requires exactly one arithmetic representation.');
        }
    }

    public static function backendName(): string
    {
        $requested = strtolower((string) (getenv('OPENPAIRING_BIGINT_BACKEND') ?: 'auto'));
        if (!in_array($requested, ['auto', 'limbs', 'gmp'], true)) {
            throw new \InvalidArgumentException('OPENPAIRING_BIGINT_BACKEND must be auto, limbs or gmp.');
        }
        if ($requested === 'gmp' && !extension_loaded('gmp')) {
            throw new \RuntimeException('The GMP big-integer backend was requested but ext-gmp is unavailable.');
        }

        return $requested === 'limbs' || !extension_loaded('gmp') ? 'limbs-30' : 'gmp';
    }

    public static function zero(int $bitWidth = 128): self
    {
        if ($bitWidth < 1) {
            throw new \InvalidArgumentException('DynamicUInt bit width must be positive.');
        }

        $effectiveWidth = intdiv($bitWidth + self::LIMB_BITS - 1, self::LIMB_BITS) * self::LIMB_BITS;
        if (self::backendName() === 'gmp') {
            $mask = gmp_sub(gmp_pow(2, $effectiveWidth), 1);

            return new self([], gmp_init(0), $mask, $effectiveWidth);
        }

        /** @var non-empty-list<int> $limbs */
        $limbs = array_fill(0, intdiv($effectiveWidth, self::LIMB_BITS), 0);

        return new self($limbs);
    }

    public static function fromInt(int $value, int $bitWidth = 128): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('DynamicUInt cannot be constructed from a negative integer.');
        }
        $result = self::zero($bitWidth);
        if ($result->native !== null) {
            $native = gmp_init($value);
            if (gmp_cmp($native, $result->nativeMask()) > 0) {
                throw new \OverflowException('Integer does not fit the requested DynamicUInt width.');
            }

            return new self([], $native, $result->nativeMask(), $result->nativeBitWidth);
        }
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
        return $this->native !== null ? $this->nativeBitWidth : count($this->limbs) * self::LIMB_BITS;
    }

    public function isZero(): bool
    {
        if ($this->native !== null) {
            return gmp_cmp($this->native, 0) === 0;
        }
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
        if ($this->native !== null) {
            return gmp_cmp($this->native, $other->nativeValue());
        }
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
        if ($this->native !== null) {
            return $this->fromNative(gmp_and(gmp_add($this->native, $other->nativeValue()), $this->nativeMask()));
        }
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
        if ($this->native !== null) {
            $difference = gmp_sub($this->native, $other->nativeValue());
            if (gmp_cmp($difference, 0) < 0) {
                $difference = gmp_add($difference, gmp_add($this->nativeMask(), 1));
            }

            return $this->fromNative(gmp_and($difference, $this->nativeMask()));
        }
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
        if ($this->native !== null) {
            if ($bits >= $this->nativeBitWidth) {
                return $this->fromNative(gmp_init(0));
            }

            return $this->fromNative(gmp_and(gmp_mul($this->native, gmp_pow(2, $bits)), $this->nativeMask()));
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
        if ($this->native !== null) {
            if ($bits >= $this->nativeBitWidth) {
                return $this->fromNative(gmp_init(0));
            }

            return $this->fromNative(gmp_div_q($this->native, gmp_pow(2, $bits)));
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
        if ($this->native !== null) {
            return $this->fromNative(gmp_or($this->native, $value));
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
        if ($this->native !== null) {
            return gmp_intval(gmp_and($this->native, $mask));
        }

        return $this->limbs[0] & $mask;
    }

    public function toHex(): string
    {
        if ($this->native !== null) {
            return gmp_strval($this->native, 16);
        }
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
        if (($this->native === null) !== ($other->native === null)) {
            throw new \InvalidArgumentException('DynamicUInt operands must use the same arithmetic backend.');
        }
        if ($this->bitWidth() !== $other->bitWidth()) {
            throw new \InvalidArgumentException('DynamicUInt operands must have equal width.');
        }
    }

    private function fromNative(\GMP $value): self
    {
        return new self([], $value, $this->nativeMask(), $this->nativeBitWidth);
    }

    private function nativeValue(): \GMP
    {
        if ($this->native === null) {
            throw new \LogicException('The GMP value is unavailable on the limb backend.');
        }

        return $this->native;
    }

    private function nativeMask(): \GMP
    {
        if ($this->nativeMask === null) {
            throw new \LogicException('The GMP mask is unavailable on the limb backend.');
        }

        return $this->nativeMask;
    }
}
