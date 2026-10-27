<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Diagnostics\ShadowOraclePolicy;
use OpenPairing\Diagnostics\ShadowPairingCategory;
use PHPUnit\Framework\TestCase;

final class ShadowOraclePolicyTest extends TestCase
{
    public function testSameAndDivergentDeviationAlwaysGoToGacrux(): void
    {
        self::assertTrue(ShadowOraclePolicy::requiresGacrux(ShadowPairingCategory::SameDeviation));
        self::assertTrue(ShadowOraclePolicy::requiresGacrux(ShadowPairingCategory::DivergentDeviation));
        self::assertFalse(ShadowOraclePolicy::requiresGacrux(ShadowPairingCategory::CandidateProgress));
        self::assertTrue(ShadowOraclePolicy::blocksRelease(ShadowPairingCategory::CandidateRegression));
    }
}
