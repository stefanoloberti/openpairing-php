<?php

declare(strict_types=1);

namespace OpenPairing\Diagnostics;

final class ShadowOraclePolicy
{
    public static function requiresGacrux(ShadowPairingCategory $category): bool
    {
        return $category === ShadowPairingCategory::SameDeviation
            || $category === ShadowPairingCategory::DivergentDeviation;
    }

    public static function blocksRelease(ShadowPairingCategory $category): bool
    {
        return $category === ShadowPairingCategory::CandidateRegression
            || self::requiresGacrux($category);
    }
}
