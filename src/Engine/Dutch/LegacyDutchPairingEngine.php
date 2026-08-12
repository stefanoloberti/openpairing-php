<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Application\PairingEngineInterface;
use OpenPairing\Diagnostics\Diagnostic;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Pairing\PairingTable;
use OpenPairing\Domain\Pairing\FloatAssignment;
use OpenPairing\Domain\Pairing\FloatDirection;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Validation\PairingResultValidator;
use OpenPairing\Validation\TournamentStateValidator;

/**
 * Bounded exhaustive implementation retained as an independent small-field
 * regression oracle. Production callers use DutchPairingEngine.
 */
final class LegacyDutchPairingEngine implements PairingEngineInterface
{
    public const VERSION = 'fide-dutch-2026.brackets/1';
    private const MAX_SEARCH_NODES = 250_000;
    private const MAX_CANDIDATES = 8_192;

    private int $searchNodes = 0;
    private int $generation = 0;
    private int $backtracks = 0;

    public function __construct(
        private readonly DutchRules $rules = new DutchRules(),
        private readonly ColourAllocator $colourAllocator = new ColourAllocator(),
    ) {
    }

    public function pair(TournamentState $state): PairingResult
    {
        (new TournamentStateValidator($this->rules))->validate($state);
        if ($state->pairingSystem !== 'fide-dutch-2026') {
            throw new InvalidTournamentState('pairing_system.unsupported', 'DutchPairingEngine requires fide-dutch-2026.');
        }
        if ($state->round > $state->numberOfRounds) {
            throw new InvalidTournamentState('round.complete', 'All configured rounds have already been completed.');
        }
        if ($state->round === 1 && !$this->hasVirtualPoints($state->players)) {
            return (new InitialDutchPairingEngine())->pair($state);
        }

        $active = $state->activePlayers();
        $this->sortPlayers($active);
        $byeCandidates = $this->roundByeCandidates($state, $active);
        if ($byeCandidates === []) {
            return $this->failure($active, 'engine.no_eligible_bye', 'No active player satisfies absolute PAB criterion C2.');
        }

        try {
            foreach ($byeCandidates as $bye) {
                $this->searchNodes = 0;
                $this->generation = 0;
                $this->backtracks = 0;
                $pairable = array_values(array_filter(
                    $active,
                    static fn (Player $player): bool => $bye === null || $player->id !== $bye->id,
                ));
                $groups = $this->scoreGroups($pairable);
                $solution = $this->solveGroups($state, $groups, 0, []);
                if ($solution === null) {
                    continue;
                }

                $tables = $this->tables($state, $solution->pairs);
                $trace = $solution->trace;
                if ($bye !== null) {
                    array_unshift($trace, [
                        'step' => 1,
                        'code' => 'pab.selected',
                        'detail' => sprintf('Selected TPN %d under C2/C5/C9.', $bye->pairingNumber),
                    ]);
                }
                $trace = $this->renumberTrace($trace);
                $result = new PairingResult(
                    tables: $tables,
                    byePlayerId: $bye?->id,
                    unpairedPlayerIds: [],
                    diagnostics: [new Diagnostic(
                        'engine.dutch_brackets',
                        'Applied the FIDE Dutch 2026 bracket search.',
                        context: [
                            'searchNodes' => $this->searchNodes,
                            'candidates' => $this->generation,
                            'backtracks' => $this->backtracks,
                            'activePlayers' => count($active),
                        ],
                    )],
                    violations: [],
                    qualityVector: $solution->quality,
                    trace: $trace,
                    algorithmVersion: self::VERSION,
                    floatAssignments: $this->floatAssignments($solution->pairs, $bye),
                );
                $violations = (new PairingResultValidator())->validate($state, $result);
                if ($violations !== []) {
                    throw new InvalidTournamentState('engine.internal_result', 'The generated result violates structural invariants.');
                }

                return $result;
            }
        } catch (SearchLimitReached) {
            return $this->failure($active, 'engine.resource_limit', sprintf('Bracket search exceeded %d nodes or %d candidates.', self::MAX_SEARCH_NODES, self::MAX_CANDIDATES));
        }

        return $this->failure($active, 'engine.no_solution', 'No complete round pairing satisfies absolute criteria C1-C4.');
    }

    /**
     * @param list<list<Player>> $groups
     * @param list<Player> $movedDown
     */
    private function solveGroups(TournamentState $state, array $groups, int $index, array $movedDown): ?TournamentPairingSolution
    {
        if ($index >= count($groups)) {
            return $movedDown === [] ? new TournamentPairingSolution([], [], []) : null;
        }

        $residents = $groups[$index];
        $isLast = $index === count($groups) - 1;
        foreach ($this->candidates($state, $movedDown, $residents, $isLast) as $candidate) {
            $tail = $this->solveGroups($state, $groups, $index + 1, $candidate->downfloaters);
            if ($tail === null) {
                ++$this->backtracks;
                continue;
            }
            $scoreLabel = $residents[0]->pairingScore()->toDecimal(1);
            $trace = [[
                'step' => 0,
                'code' => 'bracket.accepted',
                'detail' => sprintf(
                    'Accepted score bracket %s with %d MDP(s), %d pair(s), %d downfloater(s).',
                    $scoreLabel,
                    count($movedDown),
                    count($candidate->pairs),
                    count($candidate->downfloaters),
                ),
            ], ...$tail->trace];

            return new TournamentPairingSolution(
                [...$candidate->pairs, ...$tail->pairs],
                $this->addQuality($candidate->quality, $tail->quality),
                $trace,
            );
        }

        return null;
    }

    /**
     * @param list<Player> $movedDown
     * @param list<Player> $residents
     * @return list<BracketCandidate>
     */
    private function candidates(TournamentState $state, array $movedDown, array $residents, bool $isLast): array
    {
        $players = [...$movedDown, ...$residents];
        $mdpIds = array_fill_keys(array_map(static fn (Player $player): int => $player->id, $movedDown), true);
        $candidates = [];
        $this->enumerate($state, $players, [], [], $mdpIds, $residents, $isLast, $candidates);
        usort($candidates, fn (BracketCandidate $left, BracketCandidate $right): int => $this->compareCandidates($left, $right));

        return $candidates;
    }

    /**
     * @param list<Player> $remaining
     * @param list<array{left: Player, right: Player}> $pairs
     * @param list<Player> $unpaired
     * @param array<int, true> $mdpIds
     * @param list<Player> $residents
     * @param list<BracketCandidate> $candidates
     */
    private function enumerate(
        TournamentState $state,
        array $remaining,
        array $pairs,
        array $unpaired,
        array $mdpIds,
        array $residents,
        bool $isLast,
        array &$candidates,
    ): void {
        if (++$this->searchNodes > self::MAX_SEARCH_NODES || count($candidates) >= self::MAX_CANDIDATES) {
            throw new SearchLimitReached();
        }
        if ($remaining === []) {
            if (!$isLast || $unpaired === []) {
                $candidates[] = new BracketCandidate(
                    $pairs,
                    $unpaired,
                    $this->quality($state, $pairs, $unpaired, $mdpIds, $residents),
                    $this->floatScoreDifferences($pairs, $mdpIds),
                    $this->heterogeneousOrderQuality($pairs, $mdpIds, $residents),
                    $this->homogeneousOrderQuality($pairs, $mdpIds, $residents),
                    $this->generation++,
                );
            }

            return;
        }

        $first = array_shift($remaining);
        foreach ($remaining as $opponentIndex => $opponent) {
            if ((isset($mdpIds[$first->id]) && isset($mdpIds[$opponent->id])) || !$this->rules->canMeet($state, $first, $opponent)) {
                continue;
            }
            $next = $remaining;
            array_splice($next, $opponentIndex, 1);
            $this->enumerate(
                $state,
                $next,
                [...$pairs, ['left' => $first, 'right' => $opponent]],
                $unpaired,
                $mdpIds,
                $residents,
                $isLast,
                $candidates,
            );
        }
        if (!$isLast) {
            $this->enumerate($state, $remaining, $pairs, [...$unpaired, $first], $mdpIds, $residents, false, $candidates);
        }
    }

    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @param list<Player> $downfloaters
     * @param array<int, true> $mdpIds
     * @param list<Player> $residents
     * @return list<int>
     */
    private function quality(TournamentState $state, array $pairs, array $downfloaters, array $mdpIds, array $residents): array
    {
        $c10 = $c11 = $c12 = $c13 = $c14 = $c15 = $c16 = $c17 = 0;
        $residentIds = array_fill_keys(array_map(static fn (Player $player): int => $player->id, $residents), true);
        foreach ($pairs as $pair) {
            $allocation = $this->colourAllocator->allocate($state, $pair['left'], $pair['right']);
            foreach ([[$allocation->white, Colour::White], [$allocation->black, Colour::Black]] as [$player, $colour]) {
                $preference = $this->rules->colourPreference($player);
                if ($preference->colour !== null && $preference->colour !== $colour) {
                    ++$c12;
                    if ($preference->strength === ColourPreferenceStrength::Strong) {
                        ++$c13;
                    }
                }
                if (($this->rules->isTopscorer($state, $pair['left']) || $this->rules->isTopscorer($state, $pair['right']))
                    && abs($preference->difference + ($colour === Colour::White ? 1 : -1)) > 2) {
                    ++$c10;
                }
                $history = array_values(array_filter($player->colourHistory, static fn (?Colour $item): bool => $item !== null));
                if (count($history) >= 2 && $history[count($history) - 1] === $colour && $history[count($history) - 2] === $colour) {
                    ++$c11;
                }
            }
            $mdp = isset($mdpIds[$pair['left']->id]) ? $pair['left'] : (isset($mdpIds[$pair['right']->id]) ? $pair['right'] : null);
            if ($mdp !== null) {
                $opponent = $mdp === $pair['left'] ? $pair['right'] : $pair['left'];
                $c15 += $this->floatAt($opponent, 1, 'up');
                $c17 += $this->floatAt($opponent, 2, 'up');
            }
        }
        foreach ($downfloaters as $downfloater) {
            if (isset($residentIds[$downfloater->id])) {
                $c14 += $this->floatAt($downfloater, 1, 'down');
                $c16 += $this->floatAt($downfloater, 2, 'down');
            }
        }

        return [count($downfloaters), $c10, $c11, $c12, $c13, $c14, $c15, $c16, $c17];
    }

    private function compareCandidates(BracketCandidate $left, BracketCandidate $right): int
    {
        $countComparison = count($left->downfloaters) <=> count($right->downfloaters);
        if ($countComparison !== 0) {
            return $countComparison;
        }
        $leftDown = $left->downfloaters;
        $rightDown = $right->downfloaters;
        $this->sortPlayers($leftDown);
        $this->sortPlayers($rightDown);
        foreach ($leftDown as $index => $player) {
            $scoreComparison = $player->pairingScore()->compare($rightDown[$index]->pairingScore());
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }
        }
        $qualityComparison = $this->compareQuality($left->quality, $right->quality);
        if ($qualityComparison !== 0) {
            return $qualityComparison;
        }
        foreach (['c18', 'c19', 'c20', 'c21'] as $criterion) {
            $differenceComparison = $this->compareScoreLists(
                $left->floatScoreDifferences[$criterion],
                $right->floatScoreDifferences[$criterion],
            );
            if ($differenceComparison !== 0) {
                return $differenceComparison;
            }
        }
        $orderComparison = $this->compareQuality($left->heterogeneousOrderQuality, $right->heterogeneousOrderQuality);
        if ($orderComparison !== 0) {
            return $orderComparison;
        }
        $orderComparison = $this->compareQuality($left->homogeneousOrderQuality, $right->homogeneousOrderQuality);
        if ($orderComparison !== 0) {
            return $orderComparison;
        }

        return $left->generation <=> $right->generation;
    }

    /**
     * Implements HE1-HE2 for the current heterogeneous bracket. In this
     * bracket-by-bracket representation every resident belongs to the current
     * score group, so HE1's lower-score sentinel is necessarily zero. HE2 is
     * the resident BSN relative to the MDP prefix, indexed by MDP BSN.
     *
     * @param list<array{left: Player, right: Player}> $pairs
     * @param array<int, true> $mdpIds
     * @param list<Player> $residents
     * @return list<int>
     */
    private function heterogeneousOrderQuality(array $pairs, array $mdpIds, array $residents): array
    {
        $mdpCount = count($mdpIds);
        if ($mdpCount === 0) {
            return [];
        }
        $players = $residents;
        foreach ($pairs as $pair) {
            foreach ([$pair['left'], $pair['right']] as $player) {
                if (isset($mdpIds[$player->id])) {
                    $players[] = $player;
                }
            }
        }
        $unique = [];
        foreach ($players as $player) {
            $unique[$player->id] = $player;
        }
        $players = array_values($unique);
        $this->sortPlayers($players);
        $bsn = [];
        foreach ($players as $index => $player) {
            $bsn[$player->id] = $index + 1;
        }

        $he1 = array_fill(0, $mdpCount, 0);
        $he2 = array_fill(0, $mdpCount, 0);
        foreach ($pairs as $pair) {
            $leftMdp = isset($mdpIds[$pair['left']->id]);
            $rightMdp = isset($mdpIds[$pair['right']->id]);
            if ($leftMdp === $rightMdp) {
                continue;
            }
            $mdp = $leftMdp ? $pair['left'] : $pair['right'];
            $resident = $leftMdp ? $pair['right'] : $pair['left'];
            $mdpIndex = $bsn[$mdp->id] - 1;
            $he2[$mdpIndex] = $bsn[$resident->id] - $mdpCount;
        }

        return [...$he1, ...$he2];
    }

    /**
     * Implements the homogeneous deterministic order HE/HO successor rules
     * as an expanded lexicographic vector. Residents already paired with an
     * MDP are removed before assigning homogeneous BSNs.
     *
     * @param list<array{left: Player, right: Player}> $pairs
     * @param array<int, true> $mdpIds
     * @param list<Player> $residents
     * @return list<int>
     */
    private function homogeneousOrderQuality(array $pairs, array $mdpIds, array $residents): array
    {
        $heterogeneousResidentIds = [];
        foreach ($pairs as $pair) {
            $leftMdp = isset($mdpIds[$pair['left']->id]);
            $rightMdp = isset($mdpIds[$pair['right']->id]);
            if ($leftMdp !== $rightMdp) {
                $resident = $leftMdp ? $pair['right'] : $pair['left'];
                $heterogeneousResidentIds[$resident->id] = true;
            }
        }
        $homogeneousResidents = array_values(array_filter(
            $residents,
            static fn (Player $player): bool => !isset($heterogeneousResidentIds[$player->id]),
        ));
        $this->sortPlayers($homogeneousResidents);
        $bsn = [];
        foreach ($homogeneousResidents as $index => $player) {
            $bsn[$player->id] = $index + 1;
        }
        $homogeneousPairs = array_values(array_filter(
            $pairs,
            static fn (array $pair): bool => isset($bsn[$pair['left']->id], $bsn[$pair['right']->id]),
        ));
        $pairCount = count($homogeneousPairs);
        $bracketSize = count($homogeneousResidents);
        if ($pairCount === 0) {
            return [];
        }

        $ho1 = $ho2 = 0;
        $ho3 = array_fill(0, $pairCount, 0);
        // Each homogeneous edge contributes an all-one HO4 vector and clears
        // the component selected by its first BSN (Gacrux update_homogenious).
        $ho4 = array_fill(0, max(0, $bracketSize - $pairCount), $pairCount);
        $ho5 = array_fill(0, max(0, $bracketSize - 1), 0);
        foreach ($homogeneousPairs as $pair) {
            $first = min($bsn[$pair['left']->id], $bsn[$pair['right']->id]);
            $second = max($bsn[$pair['left']->id], $bsn[$pair['right']->id]);
            if ($first > $pairCount) {
                ++$ho1;
            }
            $ho2 += $first - 1;
            if ($first <= $pairCount) {
                ++$ho3[$pairCount - $first];
            } else {
                --$ho4[$first - $pairCount - 1];
            }
            $ho5[$first - 1] += $second - $first;
        }

        $quality = [$ho1, $ho2, ...$ho3, ...$ho4, ...$ho5];
        return $quality;
    }

    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @param array<int, true> $mdpIds
     * @return array{c18: list<\OpenPairing\Domain\Score\Score>, c19: list<\OpenPairing\Domain\Score\Score>, c20: list<\OpenPairing\Domain\Score\Score>, c21: list<\OpenPairing\Domain\Score\Score>}
     */
    private function floatScoreDifferences(array $pairs, array $mdpIds): array
    {
        $differences = ['c18' => [], 'c19' => [], 'c20' => [], 'c21' => []];
        foreach ($pairs as $pair) {
            $mdp = isset($mdpIds[$pair['left']->id]) ? $pair['left'] : (isset($mdpIds[$pair['right']->id]) ? $pair['right'] : null);
            if ($mdp === null) {
                continue;
            }
            $opponent = $mdp === $pair['left'] ? $pair['right'] : $pair['left'];
            $difference = $mdp->pairingScore()->subtract($opponent->pairingScore());
            if ($this->floatAt($mdp, 1, 'down') === 1) {
                $differences['c18'][] = $difference;
            }
            if ($this->floatAt($opponent, 1, 'up') === 1) {
                $differences['c19'][] = $difference;
            }
            if ($this->floatAt($mdp, 2, 'down') === 1) {
                $differences['c20'][] = $difference;
            }
            if ($this->floatAt($opponent, 2, 'up') === 1) {
                $differences['c21'][] = $difference;
            }
        }
        foreach ($differences as &$criterionDifferences) {
            usort($criterionDifferences, static fn ($left, $right): int => $right->compare($left));
        }
        unset($criterionDifferences);

        return $differences;
    }

    /**
     * @param list<\OpenPairing\Domain\Score\Score> $left
     * @param list<\OpenPairing\Domain\Score\Score> $right
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
     * @param list<Player> $players
     * @return list<list<Player>>
     */
    private function scoreGroups(array $players): array
    {
        $this->sortPlayers($players);
        $groups = [];
        foreach ($players as $player) {
            $lastIndex = array_key_last($groups);
            if ($lastIndex === null || $groups[$lastIndex][0]->pairingScore()->compare($player->pairingScore()) !== 0) {
                $groups[] = [$player];
            } else {
                $groups[$lastIndex][] = $player;
            }
        }

        return $groups;
    }

    /**
     * @param list<Player> $active
     * @return list<Player|null>
     */
    private function roundByeCandidates(TournamentState $state, array $active): array
    {
        return count($active) % 2 === 0 ? [null] : $this->byeCandidates($state, $active);
    }

    /**
     * @param list<Player> $active
     * @return list<Player>
     */
    private function byeCandidates(TournamentState $state, array $active): array
    {
        $eligible = array_values(array_filter($active, fn (Player $player): bool => $this->rules->isEligibleForPairingAllocatedBye($state, $player)));
        usort($eligible, static function (Player $left, Player $right): int {
            $score = $left->pairingScore()->compare($right->pairingScore());
            if ($score !== 0) {
                return $score;
            }
            $leftUnplayed = count(array_filter($left->results, static fn (array $result): bool => !$result['played']));
            $rightUnplayed = count(array_filter($right->results, static fn (array $result): bool => !$result['played']));

            return ($leftUnplayed <=> $rightUnplayed) ?: ($right->pairingNumber <=> $left->pairingNumber);
        });

        return $eligible;
    }

    /**
     * @param list<array{left: Player, right: Player}> $pairs
     * @return list<PairingTable>
     */
    private function tables(TournamentState $state, array $pairs): array
    {
        usort($pairs, function (array $left, array $right): int {
            $leftHigher = $this->higherRanked($left['left'], $left['right']);
            $rightHigher = $this->higherRanked($right['left'], $right['right']);
            $score = $rightHigher->pairingScore()->compare($leftHigher->pairingScore());

            return $score !== 0 ? $score : $leftHigher->pairingNumber <=> $rightHigher->pairingNumber;
        });
        $tables = [];
        foreach ($pairs as $index => $pair) {
            $allocation = $this->colourAllocator->allocate($state, $pair['left'], $pair['right']);
            $tables[] = new PairingTable($index + 1, $allocation->white->id, $allocation->black->id);
        }

        return $tables;
    }

    private function higherRanked(Player $left, Player $right): Player
    {
        $score = $left->pairingScore()->compare($right->pairingScore());

        return $score > 0 || ($score === 0 && $left->pairingNumber < $right->pairingNumber) ? $left : $right;
    }

    /** @param list<Player> $players */
    private function sortPlayers(array &$players): void
    {
        usort($players, static function (Player $left, Player $right): int {
            $score = $right->pairingScore()->compare($left->pairingScore());

            return $score !== 0 ? $score : $left->pairingNumber <=> $right->pairingNumber;
        });
    }

    /** @param list<Player> $players */
    private function hasVirtualPoints(array $players): bool
    {
        foreach ($players as $player) {
            if ($player->acceleration !== null && $player->acceleration->compare(\OpenPairing\Domain\Score\Score::zero()) !== 0) {
                return true;
            }
        }

        return false;
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
     * @param list<int> $left
     * @param list<int> $right
     */
    private function compareQuality(array $left, array $right): int
    {
        $length = max(count($left), count($right));
        for ($index = 0; $index < $length; ++$index) {
            $comparison = ($left[$index] ?? 0) <=> ($right[$index] ?? 0);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     * @return list<int>
     */
    private function addQuality(array $left, array $right): array
    {
        $sum = [];
        $length = max(count($left), count($right));
        for ($index = 0; $index < $length; ++$index) {
            $sum[] = ($left[$index] ?? 0) + ($right[$index] ?? 0);
        }

        return $sum;
    }

    private function floatAt(Player $player, int $offset, string $value): int
    {
        $index = count($player->floatHistory) - $offset;

        return $index >= 0 && ($player->floatHistory[$index] ?? null) === $value ? 1 : 0;
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
}
