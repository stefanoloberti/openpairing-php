<?php

declare(strict_types=1);

namespace OpenPairing\Engine\Dutch\GlobalMatching;

use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Engine\Matching\WeightedEdge;

/** Builds a reweightable whole-round graph from the canonical quality model. */
final readonly class DutchQualityRoundGraphBuilder
{
    public function __construct(
        private DutchRoundGraphBuilder $graphBuilder = new DutchRoundGraphBuilder(),
        private DutchQualityModel $qualityModel = new DutchQualityModel(),
    ) {
    }

    /**
     * @param list<int> $remainingPlayerIds
     * @param array<int, int> $bsnByPlayerId
     * @return list<WeightedEdge>
     */
    public function edges(
        TournamentState $state,
        int $scoreLevel,
        ?int $pabScoreLevel,
        array $remainingPlayerIds = [],
        array $bsnByPlayerId = [],
        int $homogeneousPairs = 0,
    ): array {
        $graph = $this->graphBuilder->build($state);
        $remaining = $remainingPlayerIds === []
            ? array_fill_keys($graph->vertices, true)
            : array_fill_keys($remainingPlayerIds, true);
        $players = array_values(array_filter(
            $graph->playersById,
            static fn (Player $player): bool => isset($remaining[$player->id]),
        ));
        $edges = [];
        foreach ($graph->compatibleEdges as $edge) {
            if (!isset($remaining[$edge['left']], $remaining[$edge['right']])) {
                continue;
            }
            $quality = $this->qualityModel->edge(
                $state,
                $graph->playersById[$edge['left']],
                $graph->playersById[$edge['right']],
                $scoreLevel,
                $pabScoreLevel,
                $graph->scoreRankByPlayerId,
                $players,
                $bsnByPlayerId,
                $homogeneousPairs,
            );
            $edges[] = new WeightedEdge($edge['left'], $edge['right'], $quality->maximizingWeight());
        }

        return $edges;
    }

    /**
     * @param list<int> $weightShape
     * @return list<WeightedEdge>
     */
    public function pabEdges(TournamentState $state, int $scoreLevel, int $pabScoreLevel, bool $c9Applies, array $weightShape, int $virtualByeVertex = 0): array
    {
        $graph = $this->graphBuilder->build($state);
        $maximumPointDifference = max($graph->scoreRankByPlayerId ?: [0]) - $scoreLevel;
        // QC6, expanded QC7, QN8 and expanded QC8 precede QC9.
        $qc9Offset = 1 + $maximumPointDifference + 1 + ($maximumPointDifference + 1);
        $edges = [];
        foreach ($graph->playersById as $player) {
            if ($graph->scoreRankByPlayerId[$player->id] !== $pabScoreLevel
                || !(new \OpenPairing\Engine\Dutch\DutchRules())->isEligibleForPairingAllocatedBye($state, $player)) {
                continue;
            }
            $weight = $c9Applies
                ? $this->qualityModel->pabWeight($state, $player, count($weightShape), $qc9Offset)
                : array_fill(0, count($weightShape), 0);
            $edges[] = new WeightedEdge($virtualByeVertex, $player->id, $weight);
        }

        return $edges;
    }
}
