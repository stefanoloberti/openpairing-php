<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch;

use OpenPairing\Application\PairingEngineInterface;
use OpenPairing\Diagnostics\Diagnostic;
use OpenPairing\Domain\Pairing\FloatAssignment;
use OpenPairing\Domain\Pairing\FloatDirection;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Pairing\PairingTable;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchGlobalRoundResult;
use OpenPairing\Engine\Dutch\GlobalMatching\DutchShadowGlobalCoordinator;
use OpenPairing\Engine\Matching\WeightedMatchingStatus;
use OpenPairing\Validation\PairingResultValidator;
use OpenPairing\Validation\TournamentStateValidator;

/** Production FIDE Dutch engine backed by one persistent whole-round graph. */
final readonly class DutchPairingEngine implements PairingEngineInterface
{
    public const VERSION = 'fide-dutch-2026.global/1';

    public function __construct(
        private DutchRules $rules = new DutchRules(),
        private ColourAllocator $colourAllocator = new ColourAllocator(),
        private DutchShadowGlobalCoordinator $coordinator = new DutchShadowGlobalCoordinator(),
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
        $global = $this->coordinator->match($state);
        if ($global->status !== WeightedMatchingStatus::Optimal) {
            return $this->failure($active, 'engine.no_solution', 'No complete round pairing satisfies absolute criteria C1-C4.');
        }

        $players = [];
        foreach ($state->players as $player) {
            $players[$player->id] = $player;
        }
        $pairs = [];
        foreach ($global->pairs as $pair) {
            if (!isset($players[$pair['left']], $players[$pair['right']])) {
                throw new InvalidTournamentState('engine.internal_result', 'Global matcher returned an unknown player.');
            }
            $pairs[] = ['left' => $players[$pair['left']], 'right' => $players[$pair['right']]];
        }
        $bye = $global->byePlayerId === null ? null : ($players[$global->byePlayerId] ?? null);
        if ($global->byePlayerId !== null && !$bye instanceof Player) {
            throw new InvalidTournamentState('engine.internal_result', 'Global matcher returned an unknown PAB assignee.');
        }

        $result = new PairingResult(
            tables: $this->tables($state, $pairs),
            byePlayerId: $bye?->id,
            unpairedPlayerIds: [],
            diagnostics: [$this->diagnostic($active, $global)],
            violations: [],
            qualityVector: [],
            trace: $this->trace($global),
            algorithmVersion: self::VERSION,
            floatAssignments: $this->floatAssignments($pairs, $bye),
        );
        if ((new PairingResultValidator())->validate($state, $result) !== []) {
            throw new InvalidTournamentState('engine.internal_result', 'The global result violates structural invariants.');
        }

        return $result;
    }

    /**
     * @param list<Player> $active
     */
    private function diagnostic(array $active, DutchGlobalRoundResult $global): Diagnostic
    {
        $operations = 0;
        $milliseconds = 0.0;
        foreach ($global->timings as $timing) {
            $operations += $timing->matchingOperations;
            $milliseconds += $timing->milliseconds;
        }

        return new Diagnostic(
            'engine.dutch_global',
            'Applied the FIDE Dutch 2026 persistent whole-round matcher.',
            context: [
                'activePlayers' => count($active),
                'matchingCalls' => count($global->timings),
                'matchingOperations' => $operations,
                'matchingMilliseconds' => (int) round($milliseconds),
            ],
        );
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

    /** @return list<array{step: int, code: string, detail: string}> */
    private function trace(DutchGlobalRoundResult $global): array
    {
        $trace = [];
        foreach ($global->freezeEvents as $event) {
            $trace[] = [
                'step' => count($trace) + 1,
                'code' => $event->left === 0 ? 'pab.selected' : 'pair.frozen',
                'detail' => $event->left === 0
                    ? sprintf('Selected player %d as PAB at score level %d.', $event->right, $event->scoreLevel)
                    : sprintf('Froze players %d and %d at score level %d.', $event->left, $event->right, $event->scoreLevel),
            ];
        }

        return $trace;
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
