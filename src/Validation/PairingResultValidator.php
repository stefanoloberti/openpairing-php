<?php

declare(strict_types=1);

namespace OpenPairing\Validation;

use OpenPairing\Diagnostics\Diagnostic;
use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Burstein\BursteinColourAllocator;
use OpenPairing\Engine\Burstein\BursteinRanker;
use OpenPairing\Engine\Burstein\BursteinRules;
use OpenPairing\Engine\Dutch\ColourAllocator;
use OpenPairing\Engine\Dutch\DutchRules;

final class PairingResultValidator
{
    /** @return list<Diagnostic> */
    public function validate(TournamentState $state, PairingResult $result): array
    {
        $violations = [];
        /** @var array<int, Player> $active */
        $active = [];
        foreach ($state->activePlayers() as $player) {
            $active[$player->id] = $player;
        }

        $used = [];
        /** @var array<int, 'up'|'down'> $expectedFloats */
        $expectedFloats = [];
        $dutchRules = new DutchRules();
        $bursteinRules = new BursteinRules();
        $bursteinRanker = $state->pairingSystem === 'fide-burstein-2026' ? new BursteinRanker($state) : null;
        foreach ($result->tables as $table) {
            if ($table->whitePlayerId === $table->blackPlayerId) {
                $violations[] = new Diagnostic('pair.self', 'A player is paired against themselves.', 'error');
            }
            foreach ([$table->whitePlayerId, $table->blackPlayerId] as $playerId) {
                if (!isset($active[$playerId])) {
                    $violations[] = new Diagnostic('pair.inactive_or_unknown', 'A table contains an inactive or unknown player.', 'error', ['playerId' => $playerId]);
                }
                if (isset($used[$playerId])) {
                    $violations[] = new Diagnostic('pair.duplicate', 'A player is used more than once.', 'error', ['playerId' => $playerId]);
                }
                $used[$playerId] = true;
            }
            $white = $active[$table->whitePlayerId] ?? null;
            $black = $active[$table->blackPlayerId] ?? null;
            if ($white === null || $black === null || $white->id === $black->id) {
                continue;
            }
            if (in_array($black->id, $white->opponentHistory, true)) {
                $violations[] = new Diagnostic('pair.repeat', 'A table repeats a previous opponent.', 'error', [
                    'whitePlayerId' => $white->id,
                    'blackPlayerId' => $black->id,
                ]);
                continue;
            }
            $canMeet = $state->pairingSystem === 'fide-burstein-2026'
                ? $bursteinRules->canMeet($white, $black)
                : $dutchRules->canMeet($state, $white, $black);
            if (!$canMeet) {
                $violations[] = new Diagnostic('pair.absolute_colour', 'A table violates the absolute colour-preference criterion.', 'error', [
                    'whitePlayerId' => $white->id,
                    'blackPlayerId' => $black->id,
                ]);
                continue;
            }
            $allocation = $state->pairingSystem === 'fide-burstein-2026'
                ? (new BursteinColourAllocator())->allocate(
                    $state,
                    $white,
                    $black,
                    $bursteinRanker ?? throw new \LogicException('Burstein ranking was not initialized.'),
                )
                : (new ColourAllocator())->allocate($state, $white, $black);
            if ($allocation->white->id !== $white->id || $allocation->black->id !== $black->id) {
                $violations[] = new Diagnostic('colour.allocation', 'A table does not follow the colour-allocation priority.', 'error', [
                    'whitePlayerId' => $white->id,
                    'blackPlayerId' => $black->id,
                ]);
            }
            $scoreComparison = $white->pairingScore()->compare($black->pairingScore());
            if ($scoreComparison !== 0) {
                $expectedFloats[$white->id] = $scoreComparison > 0 ? 'down' : 'up';
                $expectedFloats[$black->id] = $scoreComparison > 0 ? 'up' : 'down';
            }
        }

        if ($result->byePlayerId !== null) {
            $expectedFloats[$result->byePlayerId] = 'down';
        }

        /** @var array<int, 'up'|'down'> $reportedFloats */
        $reportedFloats = [];
        foreach ($result->floatAssignments as $assignment) {
            $playerId = $assignment->playerId;
            $direction = $assignment->direction->value;
            if (isset($reportedFloats[$playerId])) {
                $violations[] = new Diagnostic('float.duplicate', 'A player has more than one float assignment.', 'error', ['playerId' => $playerId]);
                continue;
            }
            $reportedFloats[$playerId] = $direction;
        }
        ksort($expectedFloats);
        ksort($reportedFloats);
        if ($reportedFloats !== $expectedFloats) {
            $violations[] = new Diagnostic('float.mismatch', 'Float assignments do not match cross-scoregroup pairings and the PAB.', 'error', [
                'expectedCount' => count($expectedFloats),
                'reportedCount' => count($reportedFloats),
                'expected' => $this->floatSignature($expectedFloats),
                'reported' => $this->floatSignature($reportedFloats),
            ]);
        }

        if ($result->byePlayerId !== null) {
            if (!isset($active[$result->byePlayerId])) {
                $violations[] = new Diagnostic('bye.inactive_or_unknown', 'The bye belongs to an inactive or unknown player.', 'error');
            }
            if (isset($used[$result->byePlayerId])) {
                $violations[] = new Diagnostic('bye.duplicate', 'The bye player also appears at a table.', 'error');
            }
            $used[$result->byePlayerId] = true;
            $bye = $active[$result->byePlayerId] ?? null;
            if ($bye !== null && !$dutchRules->isEligibleForPairingAllocatedBye($state, $bye)) {
                $violations[] = new Diagnostic('bye.ineligible', 'The bye player violates absolute PAB criterion C2.', 'error');
            }
        }

        $reportedUnpaired = [];
        foreach ($result->unpairedPlayerIds as $playerId) {
            if (!isset($active[$playerId])) {
                $violations[] = new Diagnostic('unpaired.inactive_or_unknown', 'An unpaired entry is inactive or unknown.', 'error', ['playerId' => $playerId]);
            }
            if (isset($used[$playerId]) || isset($reportedUnpaired[$playerId])) {
                $violations[] = new Diagnostic('unpaired.duplicate', 'An unpaired player is reported more than once or also used.', 'error', ['playerId' => $playerId]);
            }
            $reportedUnpaired[$playerId] = true;
        }

        foreach (array_keys($active) as $playerId) {
            if (!isset($used[$playerId]) && !isset($reportedUnpaired[$playerId])) {
                $violations[] = new Diagnostic('round.player_lost', 'An active player is neither paired, assigned a bye, nor reported unpaired.', 'error', ['playerId' => $playerId]);
            }
        }
        if ($result->violations === [] && $result->unpairedPlayerIds !== []) {
            $violations[] = new Diagnostic('round.incomplete', 'A successful pairing result must not leave active players unpaired.', 'error');
        }
        if ($result->violations === []) {
            $expectsBye = count($active) % 2 === 1;
            if ($expectsBye !== ($result->byePlayerId !== null)) {
                $violations[] = new Diagnostic('bye.parity', 'Bye presence does not match the active-player parity.', 'error');
            }
        }

        return $violations;
    }

    /** @param array<int, 'up'|'down'> $assignments */
    private function floatSignature(array $assignments): string
    {
        $parts = [];
        foreach ($assignments as $playerId => $direction) {
            $parts[] = $playerId.':'.$direction;
        }

        return implode(',', $parts);
    }
}
