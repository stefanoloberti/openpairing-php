<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

use InvalidArgumentException;

final readonly class FideTieBreakDefinitionValidator
{
    public function validate(TieBreakDefinition $definition): void
    {
        if ($definition->reverse && $definition->code !== FideTieBreakCode::TournamentPairingNumber) {
            throw new InvalidArgumentException(sprintf('Reverse order is valid only for TPN, not %s.', $definition->code->value));
        }
        $allowedModifiers = match ($definition->code) {
            FideTieBreakCode::Buchholz,
            FideTieBreakCode::ForeBuchholz,
            FideTieBreakCode::AverageRatingOfOpponents => TieBreakModifier::cases(),
            FideTieBreakCode::SonnebornBerger,
            FideTieBreakCode::ProgressiveScore => [TieBreakModifier::None, TieBreakModifier::Cut1, TieBreakModifier::Cut2],
            default => [TieBreakModifier::None],
        };
        if (!in_array($definition->modifier, $allowedModifiers, true)) {
            throw new InvalidArgumentException(sprintf(
                'Modifier %s is not valid for %s.',
                $definition->modifier->value,
                $definition->code->value,
            ));
        }

        if ($definition->includeForfeits && !in_array($definition->code, [
            FideTieBreakCode::DirectEncounter,
            FideTieBreakCode::Buchholz,
            FideTieBreakCode::ForeBuchholz,
            FideTieBreakCode::SonnebornBerger,
        ], true)) {
            throw new InvalidArgumentException(sprintf('Option P is not valid for %s.', $definition->code->value));
        }
        if ($definition->foreBuchholz && $definition->code !== FideTieBreakCode::AverageOfOpponentsBuchholz) {
            throw new InvalidArgumentException(sprintf('Option F is not valid for %s.', $definition->code->value));
        }
        if ($definition->limitHalfPoints !== 0 && $definition->code !== FideTieBreakCode::KoyaSystem) {
            throw new InvalidArgumentException(sprintf('Limit modifier is not valid for %s.', $definition->code->value));
        }
    }

    public function validateForTournament(TieBreakDefinition $definition, bool $roundRobin): void
    {
        $this->validate($definition);
        if ($roundRobin && in_array($definition->code, [
            FideTieBreakCode::Buchholz,
            FideTieBreakCode::AverageOfOpponentsBuchholz,
            FideTieBreakCode::ForeBuchholz,
        ], true)) {
            throw new InvalidArgumentException(sprintf('%s must not be used in round-robin tournaments.', $definition->code->value));
        }
        if (!$roundRobin && $definition->code === FideTieBreakCode::KoyaSystem) {
            throw new InvalidArgumentException('KS is applicable only to round-robin tournaments.');
        }
    }
}
