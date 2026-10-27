<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Engine\Matching\DynamicUInt;
use PHPUnit\Framework\TestCase;

final class DynamicUIntTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('OPENPAIRING_BIGINT_BACKEND');
    }

    public function testCarriesAcrossThirtyBitLimbsBeyondNativeIntegerWidth(): void
    {
        $one = DynamicUInt::fromInt(1);
        $value = $one->shiftLeft(120)->add($one->shiftLeft(90))->addInt(7);

        self::assertSame('1000000040000000000000000000007', $value->toHex());
        self::assertSame(0, $value->subtract($one->shiftLeft(90))->compare($one->shiftLeft(120)->addInt(7)));
    }

    public function testShiftsRoundTripWhenNoSignificantBitsAreDiscarded(): void
    {
        $value = DynamicUInt::fromInt(0x12345678)->shiftLeft(67);

        self::assertSame('91a2b3c00000000000000000', $value->toHex());
        self::assertSame('12345678', $value->shiftRight(67)->toHex());
    }

    public function testUnsignedSubtractionWrapsAtConfiguredWidth(): void
    {
        $zero = DynamicUInt::zero(128);
        $wrapped = $zero->subtract(DynamicUInt::fromInt(1, 128));

        self::assertSame(150, $wrapped->bitWidth());
        self::assertSame('3'.str_repeat('f', 37), $wrapped->toHex());
        self::assertSame('0', $wrapped->addInt(1)->toHex());
    }

    public function testLowBitOperationsSupportPackedLexicographicWeights(): void
    {
        $value = DynamicUInt::zero()->withLowBits(5)->shiftLeft(4)->withLowBits(3);

        self::assertSame('53', $value->toHex());
        self::assertSame(3, $value->lowBits(0xf));
    }

    public function testExplicitLimbBackendRemainsAvailable(): void
    {
        putenv('OPENPAIRING_BIGINT_BACKEND=limbs');

        self::assertSame('limbs-30', DynamicUInt::backendName());
        self::assertSame('1000000000000000000000000000001', DynamicUInt::fromInt(1)->shiftLeft(120)->addInt(1)->toHex());
    }

    public function testGmpBackendHasTheSameFixedWidthSemantics(): void
    {
        if (!extension_loaded('gmp')) {
            self::markTestSkipped('ext-gmp is unavailable.');
        }
        putenv('OPENPAIRING_BIGINT_BACKEND=gmp');
        $zero = DynamicUInt::zero(128);
        $one = DynamicUInt::fromInt(1, 128);

        self::assertSame('gmp', DynamicUInt::backendName());
        self::assertSame(150, $zero->bitWidth());
        self::assertSame('3'.str_repeat('f', 37), $zero->subtract($one)->toHex());
        self::assertSame('1000000040000000000000000000007', $one->shiftLeft(120)->add($one->shiftLeft(90))->addInt(7)->toHex());
        self::assertSame(3, $zero->withLowBits(3)->lowBits(0xf));
    }
}
