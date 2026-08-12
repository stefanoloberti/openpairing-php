<?php

declare(strict_types=1);

namespace OpenPairing\Development\Oracle;

use OpenPairing\Domain\Pairing\PairingResult;
use OpenPairing\Domain\Pairing\PairingTable;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Validation\PairingResultValidator;

final readonly class DifferentialClassifier
{
    public function classify(TournamentState $state, PairingResult $php, OracleResult $oracle): ComparisonCategory
    {
        if (in_array($oracle->status, [OracleStatus::InfrastructureError, OracleStatus::OracleError, OracleStatus::Timeout], true)) {
            return ComparisonCategory::InfrastructureError;
        }
        if ($oracle->status === OracleStatus::ResourceLimit || $this->hasViolation($php, 'engine.resource_limit')) {
            return ComparisonCategory::ResourceLimit;
        }
        if (in_array($oracle->status, [OracleStatus::UnsupportedInput, OracleStatus::InvalidInput], true)) {
            return ComparisonCategory::InputUnsupported;
        }

        $phpValid = $php->violations === [] && (new PairingResultValidator())->validate($state, $php) === [];
        if ($oracle->status === OracleStatus::NoPairing) {
            return $phpValid ? ComparisonCategory::OracleDeviation : ComparisonCategory::BothInvalid;
        }
        $oraclePairing = $this->asPairingResult($oracle);
        $oracleValid = $this->oracleValid($state, $oraclePairing);
        if (!$phpValid && !$oracleValid) {
            return ComparisonCategory::BothInvalid;
        }
        if (!$phpValid) {
            return ComparisonCategory::OurViolation;
        }
        if (!$oracleValid) {
            return ComparisonCategory::UpstreamInvalid;
        }
        if ($php->byePlayerId === $oracle->byePlayerId && $this->colouredPairs($php) === $this->colouredPairs($oraclePairing)) {
            return ComparisonCategory::Exact;
        }
        if ($php->byePlayerId === $oracle->byePlayerId && $this->opponentPairs($php) === $this->opponentPairs($oraclePairing)) {
            return ComparisonCategory::SamePairsColourVariant;
        }

        return ComparisonCategory::LegitimateVariant;
    }

    private function asPairingResult(OracleResult $oracle): PairingResult
    {
        $tables = [];
        foreach ($oracle->pairs as $index => $pair) {
            $tables[] = new PairingTable($index + 1, $pair['whitePlayerId'], $pair['blackPlayerId']);
        }

        return new PairingResult($tables, $oracle->byePlayerId, [], [], [], [], [], 'oracle');
    }

    private function oracleValid(TournamentState $state, PairingResult $pairing): bool
    {
        return (new PairingResultValidator())->validate($state, $pairing) === [];
    }

    /** @return list<string> */
    private function colouredPairs(PairingResult $pairing): array
    {
        $pairs = array_map(
            static fn (PairingTable $table): string => $table->whitePlayerId.'-'.$table->blackPlayerId,
            $pairing->tables,
        );
        sort($pairs, SORT_STRING);

        return $pairs;
    }

    /** @return list<string> */
    private function opponentPairs(PairingResult $pairing): array
    {
        $pairs = array_map(
            static fn (PairingTable $table): string => min($table->whitePlayerId, $table->blackPlayerId).'-'.max($table->whitePlayerId, $table->blackPlayerId),
            $pairing->tables,
        );
        sort($pairs, SORT_STRING);

        return $pairs;
    }

    private function hasViolation(PairingResult $result, string $code): bool
    {
        foreach ($result->violations as $violation) {
            if ($violation->code === $code) {
                return true;
            }
        }

        return false;
    }
}
