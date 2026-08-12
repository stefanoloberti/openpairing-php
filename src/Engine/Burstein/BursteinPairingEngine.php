<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Burstein;

use OpenPairing\Application\PairingEngineInterface;
use OpenPairing\Diagnostics\Diagnostic;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Pairing\PairingTable;
use OpenPairing\Domain\Pairing\FloatAssignment;
use OpenPairing\Domain\Pairing\FloatDirection;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\ColourPreferenceStrength;
use OpenPairing\Engine\Dutch\DutchPairingEngine;
use OpenPairing\Engine\Dutch\SearchLimitReached;
use OpenPairing\Engine\Matching\ExactWeightedGeneralMatcher;
use OpenPairing\Engine\Matching\MatchingLimitExceeded;
use OpenPairing\Engine\Matching\WeightedEdge;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use OpenPairing\Validation\PairingResultValidator;
use OpenPairing\Validation\TournamentStateValidator;

/**
 * Exact, bounded implementation of the FIDE Burstein System effective 1 February 2026.
 *
 * It enumerates all candidates inside a bracket and compares complete round solutions
 * in C5-C8/BSN order. Limits are explicit: exhaustion never returns a partial round.
 */
final class BursteinPairingEngine implements PairingEngineInterface
{
    public const VERSION = 'fide-burstein-2026.brackets/1';
    private const MAX_SEARCH_NODES = 250_000;
    private const MAX_CANDIDATES = 8_192;

    private int $searchNodes = 0;
    private int $candidateCount = 0;
    private int $generation = 0;
    private int $backtracks = 0;

    public function __construct(
        private readonly BursteinRules $rules = new BursteinRules(),
        private readonly BursteinColourAllocator $colourAllocator = new BursteinColourAllocator(),
    ) {
    }

    public function pair(TournamentState $state): PairingResult
    {
        (new TournamentStateValidator())->validate($state);
        if ($state->pairingSystem !== 'fide-burstein-2026') {
            throw new InvalidTournamentState('pairing_system.unsupported', 'BursteinPairingEngine requires fide-burstein-2026.');
        }
        if ($state->round > $state->numberOfRounds) {
            throw new InvalidTournamentState('round.complete', 'All configured rounds have already been completed.');
        }

        $seedingRounds = min(intdiv($state->numberOfRounds, 2), 4);
        if ($state->round <= $seedingRounds) {
            return $this->seedWithDutch($state, $seedingRounds);
        }

        $active = $state->activePlayers();
        $ranker = new BursteinRanker($state);
        $byeCandidates = $this->roundByeCandidates($state, $active, $ranker);
        if ($byeCandidates === []) {
            return $this->failure($active, 'engine.no_eligible_bye', 'No active player satisfies absolute PAB criterion C2.');
        }

        try {
            foreach ($byeCandidates as $bye) {
                $this->searchNodes = 0;
                $this->candidateCount = 0;
                $this->generation = 0;
                $this->backtracks = 0;
                $pairable = array_values(array_filter(
                    $active,
                    static fn (Player $player): bool => $bye === null || $player->id !== $bye->id,
                ));
                $groups = $this->scoreGroups($pairable, $ranker);
                $solution = $this->solveGroups($state, $groups, 0, [], $ranker);
                if ($solution === null) {
                    continue;
                }

                $trace = $solution->trace;
                if ($bye !== null) {
                    $trace = [[
                        'step' => 0,
                        'code' => 'pab.selected',
                        'detail' => sprintf('Selected TPN %d under C2/C4 and procedures 3.1.3-3.1.5.', $bye->pairingNumber),
                    ], ...$trace];
                }
                $result = new PairingResult(
                    tables: $this->tables($state, $solution->pairs, $ranker),
                    byePlayerId: $bye?->id,
                    unpairedPlayerIds: [],
                    diagnostics: [new Diagnostic(
                        'engine.burstein_brackets',
                        'Applied the FIDE Burstein 2026 bracket search.',
                        context: [
                            'searchNodes' => $this->searchNodes,
                            'candidates' => $this->candidateCount,
                            'backtracks' => $this->backtracks,
                            'activePlayers' => count($active),
                            'seedingRounds' => $seedingRounds,
                        ],
                    )],
                    violations: [],
                    qualityVector: $this->qualityVector($solution),
                    trace: $this->renumberTrace($trace),
                    algorithmVersion: self::VERSION,
                    floatAssignments: $this->floatAssignments($solution->pairs, $bye),
                );
                if ((new PairingResultValidator())->validate($state, $result) !== []) {
                    throw new InvalidTournamentState('engine.internal_result', 'The generated result violates structural invariants.');
                }

                return $result;
            }
        } catch (SearchLimitReached|MatchingLimitExceeded) {
            return $this->failure($active, 'engine.resource_limit', sprintf(
                'Bounded Burstein search exceeded %d nodes, %d candidates or the %d-vertex exact-matcher limit.',
                self::MAX_SEARCH_NODES,
                self::MAX_CANDIDATES,
                ExactWeightedGeneralMatcher::MAX_VERTICES,
            ));
        }

        return $this->failure($active, 'engine.no_solution', 'No complete round pairing satisfies absolute criteria C1-C4.');
    }

    private function seedWithDutch(TournamentState $state, int $seedingRounds): PairingResult
    {
        $result = (new DutchPairingEngine())->pair($this->withPairingSystem($state, 'fide-dutch-2026'));

        return new PairingResult(
            tables: $result->tables,
            byePlayerId: $result->byePlayerId,
            unpairedPlayerIds: $result->unpairedPlayerIds,
            diagnostics: [new Diagnostic(
                'engine.burstein_seeding',
                sprintf('Applied FIDE Dutch rules for Burstein seeding round %d of %d.', $state->round, $seedingRounds),
            ), ...$result->diagnostics],
            violations: $result->violations,
            qualityVector: $result->qualityVector,
            trace: $result->trace,
            algorithmVersion: self::VERSION.'.seeding-dutch',
            floatAssignments: $result->floatAssignments,
        );
    }

    /**
     * @param list<list<Player>> $groups
     * @param list<Player> $movedDown
     */
    private function solveGroups(
        TournamentState $state,
        array $groups,
        int $index,
        array $movedDown,
        BursteinRanker $ranker,
    ): ?BursteinSolution {
        if ($index >= count($groups)) {
            return $movedDown === [] ? new BursteinSolution([], [], []) : null;
        }

        $residents = $groups[$index];
        $bracket = [...$movedDown, ...$residents];
        $ranker->sort($bracket);
        $isLast = $index === count($groups) - 1;
        $minimumOutgoing = $isLast ? 0 : count($bracket) % 2;
        $maximumOutgoing = $isLast ? 0 : count($bracket);
        for ($outgoing = $minimumOutgoing; $outgoing <= $maximumOutgoing; $outgoing += 2) {
            $candidates = count($bracket) <= ExactWeightedGeneralMatcher::MAX_VERTICES
                ? $this->matchedCandidates($state, $bracket, $outgoing, $ranker)
                : ($outgoing === $minimumOutgoing ? $this->candidates($state, $bracket, $isLast, $ranker) : []);
            $best = null;
            foreach ($candidates as $candidate) {
                $tail = $this->solveGroups($state, $groups, $index + 1, $candidate->downfloaters, $ranker);
                if ($tail === null) {
                    ++$this->backtracks;
                    continue;
                }
                $scoreLabel = $residents[0]->pairingScore()->toDecimal(1);
                $solution = new BursteinSolution(
                    [...$candidate->pairs, ...$tail->pairs],
                    [$candidate, ...$tail->brackets],
                    [[
                        'step' => 0,
                        'code' => 'bracket.accepted',
                        'detail' => sprintf(
                            'Accepted score bracket %s with %d incoming, %d pair(s), %d outgoing and %d unmet colour preference(s).',
                            $scoreLabel,
                            count($movedDown),
                            count($candidate->pairs),
                            count($candidate->downfloaters),
                            $candidate->unmetColourPreferences,
                        ),
                    ], ...$tail->trace],
                );
                if ($best === null || $this->compareSolutions($solution, $best) < 0) {
                    $best = $solution;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /**
     * Uses the independent exact general matcher to choose the best C8/BSN
     * pairing for every possible outgoing-floater set of the requested size.
     *
     * @param list<Player> $bracket
     * @return list<BursteinCandidate>
     */
    private function matchedCandidates(
        TournamentState $state,
        array $bracket,
        int $outgoingCount,
        BursteinRanker $ranker,
    ): array {
        $subsets = [];
        $this->collectSubsets($bracket, $outgoingCount, 0, [], $subsets);
        $bsn = [];
        $byId = [];
        foreach ($bracket as $index => $player) {
            $bsn[$player->id] = $index + 1;
            $byId[$player->id] = $player;
        }
        $candidates = [];
        foreach ($subsets as $downfloaters) {
            $downIds = array_fill_keys(array_map(static fn (Player $player): int => $player->id, $downfloaters), true);
            $remaining = array_values(array_filter(
                $bracket,
                static fn (Player $player): bool => !isset($downIds[$player->id]),
            ));
            $vertices = array_map(static fn (Player $player): int => $player->id, $remaining);
            $edges = [];
            foreach ($remaining as $leftIndex => $left) {
                for ($rightIndex = $leftIndex + 1; $rightIndex < count($remaining); ++$rightIndex) {
                    $right = $remaining[$rightIndex];
                    if (!$this->rules->canMeet($left, $right)) {
                        continue;
                    }
                    $pair = [['left' => $left, 'right' => $right]];
                    $weight = [-$this->unmetColourPreferences($state, $pair, $ranker)];
                    for ($position = 1; $position <= count($bracket); ++$position) {
                        $weight[] = $position === $bsn[$left->id]
                            ? $bsn[$right->id]
                            : ($position === $bsn[$right->id] ? $bsn[$left->id] : 0);
                    }
                    $edges[] = new WeightedEdge($left->id, $right->id, $weight);
                }
            }
            $matching = (new ExactWeightedGeneralMatcher())->match($vertices, $edges, true);
            $this->searchNodes += $matching->statesExplored;
            if ($this->searchNodes > self::MAX_SEARCH_NODES) {
                throw new SearchLimitReached();
            }
            if ($matching->status !== WeightedMatchingStatus::Optimal) {
                continue;
            }
            $pairs = array_map(
                static fn (array $pair): array => ['left' => $byId[$pair['left']], 'right' => $byId[$pair['right']]],
                $matching->pairs,
            );
            if (++$this->candidateCount > self::MAX_CANDIDATES) {
                throw new SearchLimitReached();
            }
            $scores = array_map(static fn (Player $player): Score => $player->pairingScore(), $downfloaters);
            usort($scores, static fn (Score $left, Score $right): int => $right->compare($left));
            $candidates[] = new BursteinCandidate(
                $pairs,
                $downfloaters,
                $scores,
                $this->unmetColourPreferences($state, $pairs, $ranker),
                $this->opponentVector($pairs, $downfloaters, $bracket),
                $this->generation++,
            );
        }
        usort($candidates, $this->compareCandidates(...));

        return $candidates;
    }

    /**
     * @param list<Player> $players
     * @param list<Player> $current
     * @param list<list<Player>> $subsets
     */
    private function collectSubsets(
        array $players,
        int $required,
        int $offset,
        array $current,
        array &$subsets,
    ): void {
        if (++$this->searchNodes > self::MAX_SEARCH_NODES) {
            throw new SearchLimitReached();
        }
        if ($required === 0) {
            $subsets[] = $current;

            return;
        }
        $lastStart = count($players) - $required;
        for ($index = $offset; $index <= $lastStart; ++$index) {
            $this->collectSubsets($players, $required - 1, $index + 1, [...$current, $players[$index]], $subsets);
        }
    }

    /**
     * @param list<Player> $bracket
     * @return list<BursteinCandidate>
     */
    private function candidates(
        TournamentState $state,
        array $bracket,
        bool $isLast,
        BursteinRanker $ranker,
    ): array {
        $candidates = [];
        $this->enumerate($state, $bracket, [], [], $bracket, $isLast, $ranker, $candidates);
        usort($candidates, $this->compareCandidates(...));

        return $candidates;
    }

    /**
     * @param list<Player> $remaining
     * @param list<array{left: Player, right: Player}> $pairs
     * @param list<Player> $downfloaters
     * @param list<Player> $rankedBracket
     * @param list<BursteinCandidate> $candidates
     */
    private function enumerate(
        TournamentState $state,
        array $remaining,
        array $pairs,
        array $downfloaters,
        array $rankedBracket,
        bool $isLast,
        BursteinRanker $ranker,
        array &$candidates,
    ): void {
        if (++$this->searchNodes > self::MAX_SEARCH_NODES || $this->candidateCount >= self::MAX_CANDIDATES) {
            throw new SearchLimitReached();
        }
        if ($remaining === []) {
            if ($isLast && $downfloaters !== []) {
                return;
            }
            ++$this->candidateCount;
            $scores = array_map(static fn (Player $player): Score => $player->pairingScore(), $downfloaters);
            usort($scores, static fn (Score $left, Score $right): int => $right->compare($left));
            $candidates[] = new BursteinCandidate(
                $pairs,
                $downfloaters,
                $scores,
                $this->unmetColourPreferences($state, $pairs, $ranker),
                $this->opponentVector($pairs, $downfloaters, $rankedBracket),
                $this->generation++,
            );

            return;
        }

        $first = array_shift($remaining);
        foreach ($remaining as $opponentIndex => $opponent) {
            if (!$this->rules->canMeet($first, $opponent)) {
                continue;
            }
            $next = $remaining;
            array_splice($next, $opponentIndex, 1);
            $this->enumerate(
                $state,
                $next,
                [...$pairs, ['left' => $first, 'right' => $opponent]],
                $downfloaters,
                $rankedBracket,
                $isLast,
                $ranker,
                $candidates,
            );
        }
        if (!$isLast) {
            $this->enumerate(
                $state,
                $remaining,
                $pairs,
                [...$downfloaters, $first],
                $rankedBracket,
                false,
                $ranker,
                $candidates,
            );
        }
    }

    private function compareCandidates(BursteinCandidate $left, BursteinCandidate $right): int
    {
        $c5 = count($left->downfloaters) <=> count($right->downfloaters);
        if ($c5 !== 0) {
            return $c5;
        }
        $c6 = $this->compareScoreLists($left->downfloaterScores, $right->downfloaterScores);
        if ($c6 !== 0) {
            return $c6;
        }
        $c8 = $left->unmetColourPreferences <=> $right->unmetColourPreferences;
        if ($c8 !== 0) {
            return $c8;
        }
        foreach ($left->opponentVector as $index => $opponent) {
            $comparison = ($right->opponentVector[$index] ?? -1) <=> $opponent;
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return $left->generation <=> $right->generation;
    }

    private function compareSolutions(BursteinSolution $left, BursteinSolution $right): int
    {
        $leftHead = $left->brackets[0] ?? null;
        $rightHead = $right->brackets[0] ?? null;
        if ($leftHead === null || $rightHead === null) {
            return ($leftHead === null ? 0 : 1) <=> ($rightHead === null ? 0 : 1);
        }
        $c5 = count($leftHead->downfloaters) <=> count($rightHead->downfloaters);
        if ($c5 !== 0) {
            return $c5;
        }
        $c6 = $this->compareScoreLists($leftHead->downfloaterScores, $rightHead->downfloaterScores);
        if ($c6 !== 0) {
            return $c6;
        }
        $c7 = $this->compareCompletion(array_slice($left->brackets, 1), array_slice($right->brackets, 1));
        if ($c7 !== 0) {
            return $c7;
        }
        $head = $this->compareCandidates($leftHead, $rightHead);
        if ($head !== 0) {
            return $head;
        }

        return $this->compareFullTails(array_slice($left->brackets, 1), array_slice($right->brackets, 1));
    }

    /**
     * @param list<BursteinCandidate> $left
     * @param list<BursteinCandidate> $right
     */
    private function compareCompletion(array $left, array $right): int
    {
        $length = min(count($left), count($right));
        for ($index = 0; $index < $length; ++$index) {
            $c5 = count($left[$index]->downfloaters) <=> count($right[$index]->downfloaters);
            if ($c5 !== 0) {
                return $c5;
            }
            $c6 = $this->compareScoreLists($left[$index]->downfloaterScores, $right[$index]->downfloaterScores);
            if ($c6 !== 0) {
                return $c6;
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * @param list<BursteinCandidate> $left
     * @param list<BursteinCandidate> $right
     */
    private function compareFullTails(array $left, array $right): int
    {
        $length = min(count($left), count($right));
        for ($index = 0; $index < $length; ++$index) {
            $comparison = $this->compareCandidates($left[$index], $right[$index]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * @param list<Score> $left
     * @param list<Score> $right
     */
    private function compareScoreLists(array $left, array $right): int
    {
        foreach ($left as $index => $score) {
            if (!isset($right[$index])) {
                return 1;
            }
            $comparison = $score->compare($right[$index]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @param list<Player> $downfloaters
     * @param list<Player> $rankedBracket
     * @return list<int>
     */
    private function opponentVector(array $pairs, array $downfloaters, array $rankedBracket): array
    {
        $bsn = [];
        foreach ($rankedBracket as $index => $player) {
            $bsn[$player->id] = $index + 1;
        }
        $opponents = array_fill(0, count($rankedBracket), 0);
        foreach ($pairs as $pair) {
            $opponents[$bsn[$pair['left']->id] - 1] = $bsn[$pair['right']->id];
            $opponents[$bsn[$pair['right']->id] - 1] = $bsn[$pair['left']->id];
        }
        foreach ($downfloaters as $player) {
            $opponents[$bsn[$player->id] - 1] = 0;
        }

        return array_values($opponents);
    }

    /** @param list<array{left: Player, right: Player}> $pairs */
    private function unmetColourPreferences(TournamentState $state, array $pairs, BursteinRanker $ranker): int
    {
        $unmet = 0;
        foreach ($pairs as $pair) {
            $allocation = $this->colourAllocator->allocate($state, $pair['left'], $pair['right'], $ranker);
            foreach ([[$allocation->white, Colour::White], [$allocation->black, Colour::Black]] as [$player, $colour]) {
                $preference = $this->rules->colourPreference($player);
                if ($preference->colour !== null && $preference->colour !== $colour) {
                    ++$unmet;
                }
            }
        }

        return $unmet;
    }

    /**
     * @param list<Player> $players
     * @return list<list<Player>>
     */
    private function scoreGroups(array $players, BursteinRanker $ranker): array
    {
        usort($players, static function (Player $left, Player $right) use ($ranker): int {
            $score = $right->pairingScore()->compare($left->pairingScore());

            return $score !== 0 ? $score : $ranker->compare($left, $right);
        });
        $groups = [];
        foreach ($players as $player) {
            $last = array_key_last($groups);
            if ($last === null || $groups[$last][0]->pairingScore()->compare($player->pairingScore()) !== 0) {
                $groups[] = [$player];
            } else {
                $groups[$last][] = $player;
            }
        }

        return $groups;
    }

    /**
     * @param list<Player> $active
     * @return list<Player|null>
     */
    private function roundByeCandidates(TournamentState $state, array $active, BursteinRanker $ranker): array
    {
        if (count($active) % 2 === 0) {
            return [null];
        }
        $eligible = array_values(array_filter(
            $active,
            fn (Player $player): bool => $this->rules->isEligibleForPairingAllocatedBye($state, $player),
        ));
        usort($eligible, static function (Player $left, Player $right) use ($ranker): int {
            $score = $left->pairingScore()->compare($right->pairingScore());
            if ($score !== 0) {
                return $score;
            }
            $played = self::playedGames($right) <=> self::playedGames($left);

            return $played !== 0 ? $played : -$ranker->compare($left, $right);
        });

        return $eligible;
    }

    private static function playedGames(Player $player): int
    {
        return count(array_filter($player->results, static fn (array $result): bool => $result['played']));
    }

    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @return list<PairingTable>
     */
    private function tables(TournamentState $state, array $pairs, BursteinRanker $ranker): array
    {
        usort($pairs, static function (array $left, array $right) use ($ranker): int {
            $leftScore = $left['left']->pairingScore()->compare($left['right']->pairingScore()) >= 0 ? $left['left']->pairingScore() : $left['right']->pairingScore();
            $rightScore = $right['left']->pairingScore()->compare($right['right']->pairingScore()) >= 0 ? $right['left']->pairingScore() : $right['right']->pairingScore();
            $score = $rightScore->compare($leftScore);
            if ($score !== 0) {
                return $score;
            }
            $leftHigher = $ranker->isHigherRanked($left['left'], $left['right']) ? $left['left'] : $left['right'];
            $rightHigher = $ranker->isHigherRanked($right['left'], $right['right']) ? $right['left'] : $right['right'];

            return $ranker->compare($leftHigher, $rightHigher);
        });
        $tables = [];
        foreach ($pairs as $index => $pair) {
            $allocation = $this->colourAllocator->allocate($state, $pair['left'], $pair['right'], $ranker);
            $tables[] = new PairingTable($index + 1, $allocation->white->id, $allocation->black->id);
        }

        return $tables;
    }

    /** @return list<int> */
    private function qualityVector(BursteinSolution $solution): array
    {
        $downfloaters = 0;
        $unmet = 0;
        foreach ($solution->brackets as $candidate) {
            $downfloaters += count($candidate->downfloaters);
            $unmet += $candidate->unmetColourPreferences;
        }

        return [$downfloaters, $unmet];
    }

    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @return list<FloatAssignment>
     */
    private function floatAssignments(array $pairs, ?Player $bye): array
    {
        $assignments = [];
        foreach ($pairs as $pair) {
            $comparison = $pair['left']->pairingScore()->compare($pair['right']->pairingScore());
            if ($comparison === 0) {
                continue;
            }
            $higher = $comparison > 0 ? $pair['left'] : $pair['right'];
            $lower = $higher === $pair['left'] ? $pair['right'] : $pair['left'];
            $assignments[] = new FloatAssignment($higher->id, FloatDirection::Down);
            $assignments[] = new FloatAssignment($lower->id, FloatDirection::Up);
        }
        if ($bye !== null) {
            $assignments[] = new FloatAssignment($bye->id, FloatDirection::Down);
        }
        usort($assignments, static fn (FloatAssignment $left, FloatAssignment $right): int => $left->playerId <=> $right->playerId);

        return $assignments;
    }

    /**
     * @param list<array{step: int, code: string, detail: string}> $trace
     * @return list<array{step: int, code: string, detail: string}>
     */
    private function renumberTrace(array $trace): array
    {
        foreach ($trace as $index => &$entry) {
            $entry['step'] = $index + 1;
        }
        unset($entry);

        return $trace;
    }

    /** @param list<Player> $active */
    private function failure(array $active, string $code, string $message): PairingResult
    {
        return new PairingResult(
            tables: [],
            byePlayerId: null,
            unpairedPlayerIds: array_map(static fn (Player $player): int => $player->id, $active),
            diagnostics: [],
            violations: [new Diagnostic($code, $message, 'error')],
            qualityVector: [],
            trace: [],
            algorithmVersion: self::VERSION,
        );
    }

    private function withPairingSystem(TournamentState $state, string $pairingSystem): TournamentState
    {
        return new TournamentState(
            schemaVersion: $state->schemaVersion,
            round: $state->round,
            numberOfRounds: $state->numberOfRounds,
            pairingSystem: $pairingSystem,
            topColour: $state->topColour,
            pointsForWin: $state->pointsForWin,
            pointsForDraw: $state->pointsForDraw,
            maxPlayers: $state->maxPlayers,
            players: $state->players,
            tournamentName: $state->tournamentName,
            pointsForLoss: $state->pointsForLoss,
            pointsForZeroPointBye: $state->pointsForZeroPointBye,
            pointsForPairingAllocatedBye: $state->pointsForPairingAllocatedBye,
            pointsForUnknown: $state->pointsForUnknown,
            city: $state->city,
            federation: $state->federation,
            startDate: $state->startDate,
            endDate: $state->endDate,
            tournamentType: $state->tournamentType,
            chiefArbiter: $state->chiefArbiter,
            deputyArbiters: $state->deputyArbiters,
            timeControlDescription: $state->timeControlDescription,
            roundDates: $state->roundDates,
            pairingController: $state->pairingController,
            tieBreaksRecord: $state->tieBreaksRecord,
            tieBreaks: $state->tieBreaks,
            encodedTimeControl: $state->encodedTimeControl,
        );
    }
}
