<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

use InvalidArgumentException;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchRules;
use OpenPairing\Validation\TournamentStateValidator;

/** Individual Swiss tie-breaks from the FIDE regulations effective 1 March 2026. */
final readonly class FideTieBreakCalculator
{
    public const VERSION = 'fide-tiebreak-2026.individual/1';

    public function __construct(private DutchRules $rules = new DutchRules())
    {
    }

    /** @return array<int, Score> keyed by canonical player ID */
    public function evaluate(TournamentState $state, TieBreakDefinition $definition): array
    {
        (new TournamentStateValidator())->validate($state);
        (new FideTieBreakDefinitionValidator())->validateForTournament(
            $definition,
            $state->pairingSystem === 'fide-round-robin',
        );
        if ($definition->code === FideTieBreakCode::DirectEncounter) {
            throw new InvalidArgumentException('Direct Encounter is group-dependent and must be applied by FideTieBreakRanker.');
        }

        $adjustedScores = $this->adjustedOpponentScores(
            $state,
            $definition->code === FideTieBreakCode::ForeBuchholz
                || ($definition->code === FideTieBreakCode::AverageOfOpponentsBuchholz && $definition->foreBuchholz),
        );
        $ratingValues = in_array($definition->code, [
            FideTieBreakCode::AverageRatingOfOpponents,
            FideTieBreakCode::TournamentPerformanceRating,
            FideTieBreakCode::PerfectTournamentPerformance,
            FideTieBreakCode::AveragePerformanceRatingOfOpponents,
            FideTieBreakCode::AveragePerfectPerformanceOfOpponents,
        ], true) ? $this->ratingValues($state, $definition) : [];
        $values = [];
        foreach ($state->players as $player) {
            $values[$player->id] = match ($definition->code) {
                FideTieBreakCode::Points => $player->score,
                FideTieBreakCode::Buchholz,
                FideTieBreakCode::ForeBuchholz => $this->buchholz(
                    $state,
                    $player,
                    $definition->modifier,
                    $adjustedScores,
                    $definition->includeForfeits,
                    $definition->code === FideTieBreakCode::ForeBuchholz,
                ),
                FideTieBreakCode::AverageOfOpponentsBuchholz => $this->averageOfOpponentsBuchholz($state, $player, $definition),
                FideTieBreakCode::SonnebornBerger => $this->sonnebornBerger(
                    $state,
                    $player,
                    $definition->modifier,
                    $adjustedScores,
                    $definition->includeForfeits,
                ),
                FideTieBreakCode::KoyaSystem => $this->koya($state, $player, $definition->limitHalfPoints),
                FideTieBreakCode::ProgressiveScore => $this->progressiveScore($state, $player, $definition->modifier),
                FideTieBreakCode::NumberOfWins => $this->numberOfWins($state, $player, false),
                FideTieBreakCode::NumberOfGamesWon => $this->numberOfWins($state, $player, true),
                FideTieBreakCode::BlackGamesPlayed => $this->blackGames($state, $player, false),
                FideTieBreakCode::BlackGamesWon => $this->blackGames($state, $player, true),
                FideTieBreakCode::RoundsElectedToPlay => $this->roundsElectedToPlay($state, $player),
                FideTieBreakCode::StandardPoints => $this->standardPoints($state, $player),
                FideTieBreakCode::TournamentPairingNumber => new Score($player->pairingNumber, 1),
                FideTieBreakCode::AverageRatingOfOpponents,
                FideTieBreakCode::TournamentPerformanceRating,
                FideTieBreakCode::PerfectTournamentPerformance,
                FideTieBreakCode::AveragePerformanceRatingOfOpponents,
                FideTieBreakCode::AveragePerfectPerformanceOfOpponents => $ratingValues[$player->id],
                FideTieBreakCode::Rating => new Score($player->rating, 1),
            };
        }

        return $values;
    }

    /** @param array<int, Score> $adjustedScores */
    private function buchholz(
        TournamentState $state,
        Player $player,
        TieBreakModifier $modifier,
        array $adjustedScores,
        bool $includeForfeits,
        bool $fore,
    ): Score {
        $elements = $this->opponentElements($state, $player, $adjustedScores, false, $includeForfeits, $fore);
        $elements = $this->applyBuchholzModifier($elements, $modifier);

        return $this->sumElements($elements);
    }

    /** @param array<int, Score> $adjustedScores */
    private function sonnebornBerger(
        TournamentState $state,
        Player $player,
        TieBreakModifier $modifier,
        array $adjustedScores,
        bool $includeForfeits,
    ): Score {
        $elements = $this->opponentElements($state, $player, $adjustedScores, true, $includeForfeits, false);
        $cuts = match ($modifier) {
            TieBreakModifier::None => 0,
            TieBreakModifier::Cut1 => 1,
            TieBreakModifier::Cut2 => 2,
            default => throw new InvalidArgumentException('Only Cut-1 and Cut-2 are supported for Sonneborn-Berger.'),
        };
        for ($cut = 0; $cut < $cuts && $elements !== []; ++$cut) {
            array_splice($elements, $this->sonnebornLeastIndex($elements), 1);
        }

        return $this->sumElements($elements);
    }

    /**
     * @param array<int, Score> $adjustedScores
     * @return list<TieBreakElement>
     */
    private function opponentElements(
        TournamentState $state,
        Player $player,
        array $adjustedScores,
        bool $weighted,
        bool $includeForfeits,
        bool $fore,
    ): array {
        $players = [];
        foreach ($state->players as $candidate) {
            $players[$candidate->id] = $candidate;
        }
        $elements = [];
        foreach ($this->roundRecords($state, $player) as $result) {
            $code = strtoupper($result['result']);
            $scheduledForfeit = ($includeForfeits || $state->pairingSystem === 'fide-round-robin')
                && !$result['played']
                && $result['opponentId'] !== null
                && in_array($code, ['+', '-'], true);
            $foreFinalPairing = $fore
                && $result['round'] === $state->numberOfRounds
                && $result['opponentId'] !== null;
            if (($result['played'] || $scheduledForfeit || $foreFinalPairing) && $result['opponentId'] !== null) {
                $opponentScore = $adjustedScores[$result['opponentId']];
            } else {
                $opponentScore = $player->score;
                if (in_array($code, ['+', '-'], true) && $result['opponentId'] !== null) {
                    $opponentScore = $this->minimum($opponentScore, $adjustedScores[$result['opponentId']]);
                } else {
                    $cap = $state->pointsForDraw->multiply(new Score($state->numberOfRounds, 1));
                    $opponentScore = $this->minimum($opponentScore, $cap);
                }
            }
            $earned = $foreFinalPairing ? $state->pointsForDraw : $this->rules->pointsForResult($state, $code);
            $elements[] = new TieBreakElement(
                $weighted ? $earned->multiply($opponentScore) : $opponentScore,
                $opponentScore,
                !$scheduledForfeit && !$foreFinalPairing && $this->isVoluntaryUnplayedRound($result),
                $result['round'],
            );
        }

        return $elements;
    }

    /**
     * @param list<TieBreakElement> $elements
     * @return list<TieBreakElement>
     */
    private function applyBuchholzModifier(array $elements, TieBreakModifier $modifier): array
    {
        $leastCuts = match ($modifier) {
            TieBreakModifier::Cut1, TieBreakModifier::Median1 => 1,
            TieBreakModifier::Cut2, TieBreakModifier::Median2 => 2,
            TieBreakModifier::None => 0,
        };
        $greatestCuts = match ($modifier) {
            TieBreakModifier::Median1 => 1,
            TieBreakModifier::Median2 => 2,
            default => 0,
        };
        for ($cut = 0; $cut < $leastCuts && $elements !== []; ++$cut) {
            array_splice($elements, $this->buchholzLeastIndex($elements), 1);
        }
        for ($cut = 0; $cut < $greatestCuts && $elements !== []; ++$cut) {
            $index = 0;
            foreach ($elements as $candidateIndex => $element) {
                if ($element->value->compare($elements[$index]->value) > 0) {
                    $index = $candidateIndex;
                }
            }
            array_splice($elements, $index, 1);
        }

        return $elements;
    }

    /** @param list<TieBreakElement> $elements */
    private function buchholzLeastIndex(array $elements): int
    {
        $least = 0;
        $leastVur = null;
        foreach ($elements as $index => $element) {
            if ($element->value->compare($elements[$least]->value) < 0) {
                $least = $index;
            }
            if ($element->voluntaryUnplayedRound
                && ($leastVur === null || $element->value->compare($elements[$leastVur]->value) < 0)) {
                $leastVur = $index;
            }
        }

        return $leastVur ?? $least;
    }

    /** @param list<TieBreakElement> $elements */
    private function sonnebornLeastIndex(array $elements): int
    {
        $least = 0;
        $leastVur = null;
        foreach ($elements as $index => $element) {
            $opponentComparison = $element->opponentScore->compare($elements[$least]->opponentScore);
            if ($opponentComparison < 0
                || ($opponentComparison === 0 && $element->value->compare($elements[$least]->value) < 0)) {
                $least = $index;
            }
            if ($element->voluntaryUnplayedRound
                && ($leastVur === null || $element->value->compare($elements[$leastVur]->value) < 0)) {
                $leastVur = $index;
            }
        }
        if ($leastVur !== null && $elements[$leastVur]->value->compare($elements[$least]->value) >= 0) {
            return $leastVur;
        }

        return $least;
    }

    /** @param list<TieBreakElement> $elements */
    private function sumElements(array $elements): Score
    {
        $sum = Score::zero();
        foreach ($elements as $element) {
            $sum = $sum->add($element->value);
        }

        return $sum;
    }

    private function progressiveScore(TournamentState $state, Player $player, TieBreakModifier $modifier): Score
    {
        $scores = [];
        $running = Score::zero();
        foreach ($this->roundRecords($state, $player) as $result) {
            $running = $running->add($this->rules->pointsForResult($state, $result['result']));
            $scores[] = $running;
        }
        $cuts = match ($modifier) {
            TieBreakModifier::None => 0,
            TieBreakModifier::Cut1 => 1,
            TieBreakModifier::Cut2 => 2,
            default => throw new InvalidArgumentException('Only Cut-1 and Cut-2 are supported for Progressive Score.'),
        };
        $scores = array_slice($scores, min($cuts, count($scores)));
        $sum = Score::zero();
        foreach ($scores as $score) {
            $sum = $sum->add($score);
        }

        return $sum;
    }

    private function numberOfWins(TournamentState $state, Player $player, bool $overTheBoardOnly): Score
    {
        $wins = 0;
        foreach ($this->roundRecords($state, $player) as $result) {
            $isWin = $overTheBoardOnly
                ? $result['played'] && in_array(strtoupper($result['result']), ['1', 'W'], true)
                : $this->rules->pointsForResult($state, $result['result'])->compare($state->pointsForWin) === 0;
            if ($isWin) {
                ++$wins;
            }
        }

        return new Score($wins, 1);
    }

    private function blackGames(TournamentState $state, Player $player, bool $winsOnly): Score
    {
        $games = 0;
        foreach ($this->roundRecords($state, $player) as $result) {
            if ($result['played']
                && $result['colour'] === Colour::Black->value
                && (!$winsOnly || in_array(strtoupper($result['result']), ['1', 'W'], true))) {
                ++$games;
            }
        }

        return new Score($games, 1);
    }

    private function roundsElectedToPlay(TournamentState $state, Player $player): Score
    {
        $elected = $state->round - 1;
        foreach ($this->roundRecords($state, $player) as $result) {
            if ($this->isVoluntaryUnplayedRound($result)) {
                --$elected;
            }
        }

        return new Score($elected, 1);
    }

    private function standardPoints(TournamentState $state, Player $player): Score
    {
        $points = Score::zero();
        $byPlayerRound = [];
        foreach ($state->players as $candidate) {
            foreach ($candidate->results as $result) {
                $byPlayerRound[$candidate->id][$result['round']] = $result;
            }
        }
        foreach ($this->roundRecords($state, $player) as $result) {
            $earned = $this->rules->pointsForResult($state, $result['result']);
            if (!$result['played']) {
                $comparison = $earned->compare($state->pointsForDraw);
            } else {
                $opponentResult = $byPlayerRound[$result['opponentId']][$result['round']]
                    ?? throw new InvalidTournamentState('tiebreak.reciprocal_missing', 'A played game has no reciprocal record.');
                $comparison = $earned->compare($this->rules->pointsForResult($state, $opponentResult['result']));
            }
            if ($comparison > 0) {
                $points = $points->add(new Score(1, 1));
            } elseif ($comparison === 0) {
                $points = $points->add(new Score(1, 2));
            }
        }

        return $points;
    }

    private function averageOfOpponentsBuchholz(
        TournamentState $state,
        Player $player,
        TieBreakDefinition $definition,
    ): Score {
        $adjusted = $this->adjustedOpponentScores($state, $definition->foreBuchholz);
        $buchholz = [];
        foreach ($state->players as $candidate) {
            $buchholz[$candidate->id] = $this->buchholz(
                $state,
                $candidate,
                TieBreakModifier::None,
                $adjusted,
                false,
                $definition->foreBuchholz,
            );
        }
        $values = [];
        foreach ($this->roundRecords($state, $player) as $result) {
            if ($result['played'] && $result['opponentId'] !== null) {
                $values[] = $buchholz[$result['opponentId']];
            }
        }

        return $this->roundedAverage($values, 100);
    }

    private function koya(TournamentState $state, Player $player, int $limitHalfPoints): Score
    {
        $maximumGames = $state->numberOfRounds;
        $type = strtoupper($state->tournamentType.' '.$state->pairingSystem);
        if (str_contains($type, 'ROUND ROBIN') || str_contains($type, 'BERGER') || preg_match('/\bRR\b/', $type) === 1) {
            $competitors = count($state->players);
            if ($competitors > 0 && $state->numberOfRounds % $competitors === 0) {
                $maximumGames -= intdiv($state->numberOfRounds, $competitors);
            }
        }
        $limit = $state->pointsForWin
            ->multiply(new Score($maximumGames, 2))
            ->add(new Score($limitHalfPoints, 2));
        $players = [];
        foreach ($state->players as $candidate) {
            $players[$candidate->id] = $candidate;
        }
        $value = Score::zero();
        foreach ($this->roundRecords($state, $player) as $result) {
            $opponentId = $result['opponentId'];
            if ($opponentId !== null && $players[$opponentId]->score->compare($limit) >= 0) {
                $value = $value->add($this->rules->pointsForResult($state, $result['result']));
            }
        }

        return $value;
    }

    /** @return array<int, Score> */
    private function ratingValues(TournamentState $state, TieBreakDefinition $definition): array
    {
        $players = [];
        foreach ($state->players as $player) {
            if ($player->rating < 1) {
                throw new InvalidTournamentState(
                    'tiebreak.rating_unrated',
                    sprintf('%s must be dropped when unrated players are present unless explicit handling is published.', $definition->code->value),
                );
            }
            $players[$player->id] = $player;
        }

        $baseCode = match ($definition->code) {
            FideTieBreakCode::AveragePerformanceRatingOfOpponents => FideTieBreakCode::TournamentPerformanceRating,
            FideTieBreakCode::AveragePerfectPerformanceOfOpponents => FideTieBreakCode::PerfectTournamentPerformance,
            default => $definition->code,
        };
        $base = [];
        foreach ($state->players as $player) {
            $games = $this->ratingGames($state, $player, $players);
            $games = $this->applyRatingModifier($games, $definition->modifier);
            $base[$player->id] = $this->ratingValue($games, $baseCode);
        }
        if ($definition->code === $baseCode) {
            return $base;
        }

        $averages = [];
        foreach ($state->players as $player) {
            $values = [];
            foreach ($this->roundRecords($state, $player) as $result) {
                if ($result['played'] && $result['opponentId'] !== null) {
                    $values[] = $base[$result['opponentId']];
                }
            }
            $averages[$player->id] = $this->roundedAverage($values, 1);
        }

        return $averages;
    }

    /**
     * @param array<int, Player> $players
     * @return list<array{rating: int, score: Score}>
     */
    private function ratingGames(TournamentState $state, Player $player, array $players): array
    {
        $games = [];
        foreach ($this->roundRecords($state, $player) as $result) {
            if (!$result['played'] || $result['opponentId'] === null) {
                continue;
            }
            $code = strtoupper($result['result']);
            $score = match ($code) {
                '1', 'W' => new Score(1, 1),
                '=', 'D' => new Score(1, 2),
                '0', 'L' => Score::zero(),
                default => throw new InvalidTournamentState('tiebreak.rating_result', sprintf('Unsupported rated-game result code "%s".', $code)),
            };
            $games[] = ['rating' => $players[$result['opponentId']]->rating, 'score' => $score];
        }

        return $games;
    }

    /**
     * @param list<array{rating: int, score: Score}> $games
     * @return list<array{rating: int, score: Score}>
     */
    private function applyRatingModifier(array $games, TieBreakModifier $modifier): array
    {
        $low = match ($modifier) {
            TieBreakModifier::Cut1, TieBreakModifier::Median1 => 1,
            TieBreakModifier::Cut2, TieBreakModifier::Median2 => 2,
            TieBreakModifier::None => 0,
        };
        $high = match ($modifier) {
            TieBreakModifier::Median1 => 1,
            TieBreakModifier::Median2 => 2,
            default => 0,
        };
        usort($games, static fn (array $left, array $right): int => $left['rating'] <=> $right['rating']);
        $games = array_slice($games, min($low, count($games)));
        if ($high > 0) {
            $games = array_slice($games, 0, max(0, count($games) - $high));
        }

        return $games;
    }

    /** @param list<array{rating: int, score: Score}> $games */
    private function ratingValue(array $games, FideTieBreakCode $code): Score
    {
        if ($games === []) {
            return Score::zero();
        }
        $ratings = array_column($games, 'rating');
        $average = $this->roundHalfUp(array_sum($ratings), count($ratings));
        if ($code === FideTieBreakCode::AverageRatingOfOpponents) {
            return new Score($average, 1);
        }
        $score = Score::zero();
        foreach ($games as $game) {
            $score = $score->add($game['score']);
        }
        if ($code === FideTieBreakCode::TournamentPerformanceRating) {
            $percentage = $this->roundHalfUp($score->units * 100, $score->scale * count($games));
            $difference = (new FideRatingTable())->ratingDifferenceForPercentage($percentage);

            return new Score($average + $difference, 1);
        }
        if ($code !== FideTieBreakCode::PerfectTournamentPerformance) {
            throw new InvalidArgumentException(sprintf('Unsupported rating calculation %s.', $code->value));
        }
        if ($score->units === 0) {
            return new Score(min($ratings) - 800, 1);
        }
        $low = min($ratings) - 801;
        $high = max($ratings) + 801;
        while ($high - $low > 1) {
            $candidate = intdiv($low + $high, 2);
            if ($this->expectedScoreAtLeast($candidate, $ratings, $score)) {
                $high = $candidate;
            } else {
                $low = $candidate;
            }
        }

        return new Score($high, 1);
    }

    /** @param list<int> $ratings */
    private function expectedScoreAtLeast(int $rating, array $ratings, Score $target): bool
    {
        $hundredths = 0;
        $table = new FideRatingTable();
        foreach ($ratings as $opponentRating) {
            $hundredths += $table->expectedScoreHundredths($rating, $opponentRating);
        }

        return $hundredths * $target->scale >= $target->units * 100;
    }

    /** @param list<Score> $values */
    private function roundedAverage(array $values, int $scale): Score
    {
        if ($values === []) {
            return Score::zero();
        }
        $sum = Score::zero();
        foreach ($values as $value) {
            $sum = $sum->add($value);
        }
        $units = $this->roundHalfUp($sum->units * $scale, $sum->scale * count($values));

        return new Score($units, $scale);
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0) {
            return -intdiv((-2 * $numerator) + $denominator, 2 * $denominator);
        }

        return intdiv((2 * $numerator) + $denominator, 2 * $denominator);
    }

    /** @return array<int, Score> */
    private function adjustedOpponentScores(TournamentState $state, bool $fore = false): array
    {
        $adjusted = [];
        foreach ($state->players as $player) {
            $score = $player->score;
            $records = $this->roundRecords($state, $player);
            foreach ($records as $index => $result) {
                $code = strtoupper($result['result']);
                if (!in_array($code, ['H', 'Z'], true) || $this->hasLaterNonVur($records, $index)) {
                    continue;
                }
                $score = $score
                    ->subtract($this->rules->pointsForResult($state, $code))
                    ->add($state->pointsForDraw);
            }
            if ($fore) {
                foreach ($records as $result) {
                    if ($result['round'] === $state->numberOfRounds && $result['opponentId'] !== null) {
                        $score = $score
                            ->subtract($this->rules->pointsForResult($state, $result['result']))
                            ->add($state->pointsForDraw);
                    }
                }
            }
            $adjusted[$player->id] = $score;
        }

        return $adjusted;
    }

    /**
     * @param list<array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool}> $records
     */
    private function hasLaterNonVur(array $records, int $index): bool
    {
        for ($later = $index + 1; $later < count($records); ++$later) {
            if (!$this->isVoluntaryUnplayedRound($records[$later])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool}>
     */
    private function roundRecords(TournamentState $state, Player $player): array
    {
        $byRound = [];
        foreach ($player->results as $result) {
            $byRound[$result['round']] = $result;
        }
        $records = [];
        for ($round = 1; $round < $state->round; ++$round) {
            $records[] = $byRound[$round] ?? [
                'round' => $round,
                'opponentId' => null,
                'colour' => null,
                'result' => 'Z',
                'played' => false,
            ];
        }

        return $records;
    }

    /** @param array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool} $result */
    private function isVoluntaryUnplayedRound(array $result): bool
    {
        return !$result['played'] && in_array(strtoupper($result['result']), ['H', 'Z', '-'], true);
    }

    private function minimum(Score $left, Score $right): Score
    {
        return $left->compare($right) <= 0 ? $left : $right;
    }
}
