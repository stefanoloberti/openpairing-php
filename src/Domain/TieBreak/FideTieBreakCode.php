<?php

declare(strict_types=1);

namespace OpenPairing\Domain\TieBreak;

enum FideTieBreakCode: string
{
    case Points = 'PTS';
    case DirectEncounter = 'DE';
    case NumberOfWins = 'WIN';
    case NumberOfGamesWon = 'WON';
    case BlackGamesPlayed = 'BPG';
    case BlackGamesWon = 'BWG';
    case ProgressiveScore = 'PS';
    case RoundsElectedToPlay = 'REP';
    case StandardPoints = 'STD';
    case TournamentPairingNumber = 'TPN';
    case Buchholz = 'BH';
    case AverageOfOpponentsBuchholz = 'AOB';
    case ForeBuchholz = 'FB';
    case SonnebornBerger = 'SB';
    case KoyaSystem = 'KS';
    case AverageRatingOfOpponents = 'ARO';
    case TournamentPerformanceRating = 'TPR';
    case PerfectTournamentPerformance = 'PTP';
    case AveragePerformanceRatingOfOpponents = 'APRO';
    case AveragePerfectPerformanceOfOpponents = 'APPO';
    case Rating = 'RTNG';
}
