<?php

declare(strict_types=1);

namespace OpenPairing\Diagnostics;

final readonly class ShadowPairingComparison
{
    /**
     * @param array{pairs: array<string, string>, bye: int|null} $reference
     * @param array{pairs: array<string, string>, bye: int|null} $legacy
     * @param array{pairs: array<string, string>, bye: int|null} $candidate
     */
    public static function classify(array $reference, array $legacy, array $candidate): ShadowPairingCategory
    {
        $legacyExact = $legacy === $reference;
        $candidateExact = $candidate === $reference;
        if ($legacyExact && $candidateExact) {
            return ShadowPairingCategory::BothExact;
        }
        if (!$legacyExact && $candidateExact) {
            return ShadowPairingCategory::CandidateProgress;
        }
        if ($legacyExact) {
            return ShadowPairingCategory::CandidateRegression;
        }

        return $legacy === $candidate
            ? ShadowPairingCategory::SameDeviation
            : ShadowPairingCategory::DivergentDeviation;
    }
}
