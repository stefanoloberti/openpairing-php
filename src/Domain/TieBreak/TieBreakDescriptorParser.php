<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

use InvalidArgumentException;

/** Parser for the case-insensitive FIDE TRF26 Rank Order Descriptor syntax. */
final readonly class TieBreakDescriptorParser
{
    /** @return list<TieBreakDefinition> */
    public function parseList(string $descriptors): array
    {
        if (trim($descriptors) === '') {
            return [];
        }

        $definitions = [];
        $items = explode(',', $descriptors);
        if (trim($items[array_key_last($items)]) === '') {
            array_pop($items);
        }
        foreach ($items as $position => $descriptor) {
            $descriptor = trim($descriptor);
            if ($descriptor === '') {
                throw new InvalidArgumentException(sprintf('Empty rank order descriptor at position %d.', $position + 1));
            }
            $definitions[] = $this->parse($descriptor);
        }

        return $definitions;
    }

    public function parse(string $descriptor): TieBreakDefinition
    {
        $parts = explode('/', strtoupper(trim($descriptor)));
        $codeText = array_shift($parts);
        if ($codeText === '' || str_contains($codeText, ':')) {
            throw new InvalidArgumentException(sprintf('Unsupported individual rank order descriptor "%s".', $descriptor));
        }
        $code = FideTieBreakCode::tryFrom($codeText);
        if ($code === null) {
            throw new InvalidArgumentException(sprintf('Unknown FIDE individual rank order acronym "%s".', $codeText));
        }

        $modifier = TieBreakModifier::None;
        $includeForfeits = false;
        $foreBuchholz = false;
        $limitHalfPoints = 0;
        foreach ($parts as $variant) {
            if ($variant === 'C1' || $variant === 'C2' || $variant === 'M1' || $variant === 'M2') {
                if ($modifier !== TieBreakModifier::None) {
                    throw new InvalidArgumentException(sprintf('Descriptor "%s" has more than one Cut/Median modifier.', $descriptor));
                }
                $modifier = match ($variant) {
                    'C1' => TieBreakModifier::Cut1,
                    'C2' => TieBreakModifier::Cut2,
                    'M1' => TieBreakModifier::Median1,
                    'M2' => TieBreakModifier::Median2,
                };
            } elseif ($variant === 'P') {
                if ($includeForfeits) {
                    throw new InvalidArgumentException(sprintf('Descriptor "%s" repeats option P.', $descriptor));
                }
                $includeForfeits = true;
            } elseif ($variant === 'F') {
                if ($foreBuchholz) {
                    throw new InvalidArgumentException(sprintf('Descriptor "%s" repeats option F.', $descriptor));
                }
                $foreBuchholz = true;
            } elseif (preg_match('/^L([+-])(\d+)$/D', $variant, $matches) === 1) {
                if ($limitHalfPoints !== 0 || (int) $matches[2] === 0) {
                    throw new InvalidArgumentException(sprintf('Descriptor "%s" has an invalid or repeated Limit modifier.', $descriptor));
                }
                $limitHalfPoints = ($matches[1] === '+' ? 1 : -1) * (int) $matches[2];
            } else {
                throw new InvalidArgumentException(sprintf('Unknown variant "%s" in descriptor "%s".', $variant, $descriptor));
            }
        }

        $definition = new TieBreakDefinition(
            $code,
            $modifier,
            false,
            $includeForfeits,
            $foreBuchholz,
            $limitHalfPoints,
        );
        (new FideTieBreakDefinitionValidator())->validate($definition);

        return $definition;
    }
}
