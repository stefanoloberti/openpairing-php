<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

/** Exact integer representation of FIDE Rating Regulations tables 8.1.1-8.1.2. */
final readonly class FideRatingTable
{
    /** Positive dp values for fractional scores 0.50 through 1.00. */
    private const POSITIVE_DP = [
        0, 7, 14, 21, 29, 36, 43, 50, 57, 65, 72, 80, 87, 95, 102, 110, 117,
        125, 133, 141, 149, 158, 166, 175, 184, 193, 202, 211, 220, 230, 240, 251,
        262, 273, 284, 296, 309, 322, 336, 351, 366, 383, 401, 422, 444, 470, 501,
        538, 589, 677, 800,
    ];

    /** Upper rating-difference bounds for probabilities .50 through .99. */
    private const PD_UPPER_BOUNDS = [
        3, 10, 17, 25, 32, 39, 46, 53, 61, 68, 76, 83, 91, 98, 106, 113, 121,
        129, 137, 145, 153, 162, 170, 179, 188, 197, 206, 215, 225, 235, 245, 256,
        267, 278, 290, 302, 315, 328, 344, 357, 374, 391, 411, 432, 456, 484, 517,
        559, 619, 735,
    ];

    public function ratingDifferenceForPercentage(int $percentage): int
    {
        $percentage = max(0, min(100, $percentage));
        if ($percentage >= 50) {
            return self::POSITIVE_DP[$percentage - 50];
        }

        return -self::POSITIVE_DP[50 - $percentage];
    }

    /** Expected score in hundredths for the first rating against the second. */
    public function expectedScoreHundredths(int $rating, int $opponentRating): int
    {
        $difference = abs($rating - $opponentRating);
        $higherProbability = 100;
        foreach (self::PD_UPPER_BOUNDS as $index => $upperBound) {
            if ($difference <= $upperBound) {
                $higherProbability = 50 + $index;
                break;
            }
        }

        return $rating >= $opponentRating ? $higherProbability : 100 - $higherProbability;
    }
}
