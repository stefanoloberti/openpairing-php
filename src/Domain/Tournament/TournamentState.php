<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Tournament;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;

final readonly class TournamentState
{
    public const SCHEMA_VERSION = 'openpairing.tournament/1';
    public const MAX_PLAYERS = 4_096;
    public const MAX_ROUNDS = 256;
    public const MAX_PAIRING_NUMBER = 9_999;

    /**
     * @param list<Player> $players
     * @param list<string> $deputyArbiters
     * @param list<string> $roundDates
     * @param list<ProhibitedPairingGroup> $prohibitedPairingGroups
     * @param list<ScheduledBye> $scheduledByes
     */
    public function __construct(
        public string $schemaVersion,
        public int $round,
        public int $numberOfRounds,
        public string $pairingSystem,
        public Colour $topColour,
        public Score $pointsForWin,
        public Score $pointsForDraw,
        public int $maxPlayers,
        public array $players,
        public string $tournamentName = '',
        public ?Score $pointsForLoss = null,
        public ?Score $pointsForZeroPointBye = null,
        public ?Score $pointsForPairingAllocatedBye = null,
        public ?Score $pointsForUnknown = null,
        public string $city = '',
        public string $federation = '',
        public string $startDate = '',
        public string $endDate = '',
        public string $tournamentType = '',
        public string $chiefArbiter = '',
        public array $deputyArbiters = [],
        public string $timeControlDescription = '',
        public array $roundDates = [],
        public string $pairingController = '',
        public string $tieBreaksRecord = '',
        public string $tieBreaks = '',
        public string $encodedTimeControl = '',
        public array $prohibitedPairingGroups = [],
        public array $scheduledByes = [],
    ) {
    }

    public function lossScore(): Score
    {
        return $this->pointsForLoss ?? Score::zero();
    }

    public function zeroPointByeScore(): Score
    {
        return $this->pointsForZeroPointBye ?? Score::zero();
    }

    public function pairingAllocatedByeScore(): Score
    {
        return $this->pointsForPairingAllocatedBye ?? $this->pointsForWin;
    }

    public function unknownScore(): Score
    {
        return $this->pointsForUnknown ?? $this->pointsForDraw;
    }

    public function isPairingProhibited(int $leftPlayerId, int $rightPlayerId, ?int $round = null): bool
    {
        $round ??= $this->round;
        foreach ($this->prohibitedPairingGroups as $group) {
            if ($group->prohibits($round, $leftPlayerId, $rightPlayerId)) {
                return true;
            }
        }

        return false;
    }

    public function hasProhibitedPairingsInRound(?int $round = null): bool
    {
        $round ??= $this->round;
        foreach ($this->prohibitedPairingGroups as $group) {
            if ($round >= $group->firstRound && $round <= $group->lastRound) {
                return true;
            }
        }

        return false;
    }

    public function isPlayerScheduledBye(int $playerId, ?int $round = null): bool
    {
        $round ??= $this->round;
        foreach ($this->scheduledByes as $scheduledBye) {
            if ($scheduledBye->includes($round, $playerId)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Player> */
    public function activePlayers(): array
    {
        return array_values(array_filter(
            $this->players,
            fn (Player $player): bool => $player->active && !$this->isPlayerScheduledBye($player->id),
        ));
    }
}
