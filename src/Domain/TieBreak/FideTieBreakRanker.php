<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

use InvalidArgumentException;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\DutchRules;
use OpenPairing\Validation\TournamentStateValidator;

final readonly class FideTieBreakRanker
{
    public function __construct(
        private FideTieBreakCalculator $calculator = new FideTieBreakCalculator(),
        private DutchRules $rules = new DutchRules(),
    ) {
    }

    /**
     * Tournament score is always the primary ranking key. Remaining ties are
     * resolved by the ordered definitions; unresolved inner groups remain tied.
     *
     * @param list<TieBreakDefinition> $definitions
     * @param list<int>|null $playerIds
     */
    public function rank(
        TournamentState $state,
        array $definitions,
        ?array $playerIds = null,
    ): TieBreakRanking {
        (new TournamentStateValidator())->validate($state);
        $players = [];
        foreach ($state->players as $player) {
            $players[$player->id] = $player;
        }
        $ids = $playerIds ?? array_keys($players);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidTournamentState('tiebreak.player_duplicate', 'Tie-break ranking player IDs must be unique.');
        }
        foreach ($ids as $id) {
            if (!isset($players[$id])) {
                throw new InvalidTournamentState('tiebreak.player_unknown', sprintf('Unknown tie-break player ID %d.', $id));
            }
        }

        usort($ids, static function (int $left, int $right) use ($players): int {
            $score = $players[$right]->score->compare($players[$left]->score);

            return $score !== 0 ? $score : $players[$left]->pairingNumber <=> $players[$right]->pairingNumber;
        });
        $groups = $this->partitionByScore($ids, $players);

        return $this->applyDefinitions($state, $definitions, $groups, $players);
    }

    /**
     * Applies a complete TRF 212 rank-order list, where PTS may occur at any position.
     *
     * @param list<TieBreakDefinition> $definitions
     * @param list<int>|null $playerIds
     */
    public function rankByCriteria(TournamentState $state, array $definitions, ?array $playerIds = null): TieBreakRanking
    {
        (new TournamentStateValidator())->validate($state);
        $players = [];
        foreach ($state->players as $player) {
            $players[$player->id] = $player;
        }
        $ids = $playerIds ?? array_keys($players);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidTournamentState('tiebreak.player_duplicate', 'Tie-break ranking player IDs must be unique.');
        }
        foreach ($ids as $id) {
            if (!isset($players[$id])) {
                throw new InvalidTournamentState('tiebreak.player_unknown', sprintf('Unknown tie-break player ID %d.', $id));
            }
        }
        usort($ids, static fn (int $left, int $right): int => $players[$left]->pairingNumber <=> $players[$right]->pairingNumber);

        return $this->applyDefinitions($state, $definitions, [$ids], $players);
    }

    public function rankFromTournamentRecord(TournamentState $state): TieBreakRanking
    {
        if ($state->tieBreaks === '' || !in_array($state->tieBreaksRecord, ['202', '212'], true)) {
            throw new InvalidTournamentState('tiebreak.record_missing', 'A valid TRF 202 or 212 rank-order record is required.');
        }
        try {
            $definitions = (new TieBreakDescriptorParser())->parseList($state->tieBreaks);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidTournamentState('tiebreak.descriptor_invalid', $exception->getMessage());
        }

        return $state->tieBreaksRecord === '202'
            ? $this->rank($state, $definitions)
            : $this->rankByCriteria($state, $definitions);
    }

    /**
     * @param list<TieBreakDefinition> $definitions
     * @param list<list<int>> $groups
     * @param array<int, Player> $players
     */
    private function applyDefinitions(TournamentState $state, array $definitions, array $groups, array $players): TieBreakRanking
    {
        $evaluations = [];
        foreach ($definitions as $definition) {
            (new FideTieBreakDefinitionValidator())->validateForTournament(
                $definition,
                $state->pairingSystem === 'fide-round-robin',
            );
            if ($definition->code === FideTieBreakCode::DirectEncounter) {
                if ($definition->modifier !== TieBreakModifier::None || $definition->reverse
                    || $definition->foreBuchholz || $definition->limitHalfPoints !== 0) {
                    throw new InvalidArgumentException('Direct Encounter accepts only option P.');
                }
                $next = [];
                foreach ($groups as $group) {
                    foreach ($this->directEncounterGroups($state, $group, $players, $definition->includeForfeits) as $resolved) {
                        $next[] = $resolved;
                    }
                }
                $groups = $next;
                continue;
            }

            $values = $this->calculator->evaluate($state, $definition);
            $evaluations[$definition->identifier()] = $values;
            $next = [];
            foreach ($groups as $group) {
                foreach ($this->partitionByValue($group, $values, $definition) as $resolved) {
                    $next[] = $resolved;
                }
            }
            $groups = $next;
        }

        return new TieBreakRanking($groups, $evaluations);
    }

    /**
     * @param list<int> $ids
     * @param array<int, Player> $players
     * @return list<list<int>>
     */
    private function partitionByScore(array $ids, array $players): array
    {
        $groups = [];
        foreach ($ids as $id) {
            $last = array_key_last($groups);
            if ($last === null || $players[$groups[$last][0]]->score->compare($players[$id]->score) !== 0) {
                $groups[] = [$id];
            } else {
                $groups[$last][] = $id;
            }
        }

        return $groups;
    }

    /**
     * @param list<int> $group
     * @param array<int, Score> $values
     * @return list<list<int>>
     */
    private function partitionByValue(array $group, array $values, TieBreakDefinition $definition): array
    {
        $ascending = $definition->code === FideTieBreakCode::TournamentPairingNumber;
        if ($definition->reverse) {
            $ascending = !$ascending;
        }
        usort($group, static function (int $left, int $right) use ($values, $ascending): int {
            $comparison = $values[$left]->compare($values[$right]);

            return $ascending ? $comparison : -$comparison;
        });
        $groups = [];
        foreach ($group as $id) {
            $last = array_key_last($groups);
            if ($last === null || $values[$groups[$last][0]]->compare($values[$id]) !== 0) {
                $groups[] = [$id];
            } else {
                $groups[$last][] = $id;
            }
        }

        return $groups;
    }

    /**
     * @param list<int> $group
     * @param array<int, Player> $players
     * @return list<list<int>>
     */
    private function directEncounterGroups(
        TournamentState $state,
        array $group,
        array $players,
        bool $includeForfeits,
    ): array
    {
        if (count($group) < 2) {
            return [$group];
        }
        $inGroup = array_fill_keys($group, true);
        $scores = array_fill_keys($group, Score::zero());
        $pairScores = [];
        $pairCounts = [];
        $met = [];
        foreach ($group as $id) {
            foreach ($players[$id]->results as $result) {
                $opponentId = $result['opponentId'];
                $forfeit = ($includeForfeits || $state->pairingSystem === 'fide-round-robin')
                    && !$result['played']
                    && in_array(strtoupper($result['result']), ['+', '-'], true);
                if ((!$result['played'] && !$forfeit) || $opponentId === null || !isset($inGroup[$opponentId])) {
                    continue;
                }
                $pairScores[$id][$opponentId] = ($pairScores[$id][$opponentId] ?? Score::zero())
                    ->add($this->rules->pointsForResult($state, $result['result']));
                $pairCounts[$id][$opponentId] = ($pairCounts[$id][$opponentId] ?? 0) + 1;
                $met[min($id, $opponentId)][max($id, $opponentId)] = true;
            }
        }
        foreach ($group as $id) {
            foreach ($pairScores[$id] ?? [] as $opponentId => $pairScore) {
                $scores[$id] = $scores[$id]->add($pairScore->multiply(new Score(1, $pairCounts[$id][$opponentId])));
            }
        }

        $allMet = true;
        foreach ($group as $leftIndex => $left) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < count($group); ++$rightIndex) {
                $right = $group[$rightIndex];
                if (!isset($met[min($left, $right)][max($left, $right)])) {
                    $allMet = false;
                }
            }
        }
        if ($allMet) {
            $partitions = $this->partitionDirectScores($group, $scores);
            if (count($partitions) === 1) {
                return [$group];
            }
            $resolved = [];
            foreach ($partitions as $partition) {
                foreach ($this->directEncounterGroups($state, $partition, $players, $includeForfeits) as $subgroup) {
                    $resolved[] = $subgroup;
                }
            }

            return $resolved;
        }

        foreach ($group as $candidate) {
            $guaranteed = true;
            foreach ($group as $other) {
                if ($candidate === $other) {
                    continue;
                }
                $maximum = $scores[$other];
                foreach ($group as $opponent) {
                    if ($opponent === $other) {
                        continue;
                    }
                    if (!isset($met[min($other, $opponent)][max($other, $opponent)])) {
                        $maximum = $maximum->add($state->pointsForWin);
                    }
                }
                if ($scores[$candidate]->compare($maximum) <= 0) {
                    $guaranteed = false;
                    break;
                }
            }
            if ($guaranteed) {
                $remaining = array_values(array_filter($group, static fn (int $id): bool => $id !== $candidate));

                return [[$candidate], ...$this->directEncounterGroups($state, $remaining, $players, $includeForfeits)];
            }
        }

        return [$group];
    }

    /**
     * @param list<int> $group
     * @param array<int, Score> $scores
     * @return list<list<int>>
     */
    private function partitionDirectScores(array $group, array $scores): array
    {
        usort($group, static fn (int $left, int $right): int => -$scores[$left]->compare($scores[$right]));
        $groups = [];
        foreach ($group as $id) {
            $last = array_key_last($groups);
            if ($last === null || $scores[$groups[$last][0]]->compare($scores[$id]) !== 0) {
                $groups[] = [$id];
            } else {
                $groups[$last][] = $id;
            }
        }

        return $groups;
    }
}
