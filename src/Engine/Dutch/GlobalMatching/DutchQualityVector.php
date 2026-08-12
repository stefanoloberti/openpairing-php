<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

/** Canonical, complete Dutch bracket-quality vector; lower is better. */
final readonly class DutchQualityVector
{
    /** @var list<string> */
    public const ORDER = [
        'QC6', 'QC7', 'QN8', 'QC8', 'QC9',
        'QC10', 'QC11', 'QC12', 'QC13', 'QC14', 'QC15', 'QC16', 'QC17',
        'QC18', 'QC19', 'QC20', 'QC21',
        'HE1', 'HE2', 'HO1', 'HO2', 'HO3', 'HO4', 'HO5',
    ];

    /** @param array<string, int|list<int>> $components */
    public function __construct(public array $components)
    {
        foreach ($components as $criterion => $value) {
            if (!in_array($criterion, self::ORDER, true)) {
                throw new \InvalidArgumentException('Unknown Dutch quality criterion: '.$criterion);
            }
        }
    }

    /** @return list<int> */
    public function flatten(): array
    {
        $flat = [];
        foreach (self::ORDER as $criterion) {
            $value = $this->components[$criterion] ?? 0;
            foreach (is_array($value) ? $value : [$value] as $component) {
                $flat[] = $component;
            }
        }

        return $flat;
    }

    /** @return list<int> Matcher weights maximize, while Dutch penalties minimize. */
    public function maximizingWeight(): array
    {
        return array_map(static fn (int $component): int => -$component, $this->flatten());
    }

    public function compare(self $other): int
    {
        foreach (self::ORDER as $criterion) {
            $left = $this->components[$criterion] ?? 0;
            $right = $other->components[$criterion] ?? 0;
            $left = is_array($left) ? $left : [$left];
            $right = is_array($right) ? $right : [$right];
            $length = max(count($left), count($right));
            for ($index = 0; $index < $length; ++$index) {
                $comparison = ($left[$index] ?? 0) <=> ($right[$index] ?? 0);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
        }

        return 0;
    }

    /** @return array{criterion: string, component: int, left: int, right: int}|null */
    public function firstDifference(self $other): ?array
    {
        foreach (self::ORDER as $criterion) {
            $left = $this->components[$criterion] ?? 0;
            $right = $other->components[$criterion] ?? 0;
            $left = is_array($left) ? $left : [$left];
            $right = is_array($right) ? $right : [$right];
            $length = max(count($left), count($right));
            for ($index = 0; $index < $length; ++$index) {
                if (($left[$index] ?? 0) !== ($right[$index] ?? 0)) {
                    return [
                        'criterion' => $criterion,
                        'component' => $index,
                        'left' => $left[$index] ?? 0,
                        'right' => $right[$index] ?? 0,
                    ];
                }
            }
        }

        return null;
    }
}
