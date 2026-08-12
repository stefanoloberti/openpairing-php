<?php

declare(strict_types=1);

namespace OpenPairing\Format\Trf;

use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Validation\TournamentStateValidator;

final class Trf2026Codec
{
    public const MAX_BYTES = 2_097_152;
    private const MAX_LINE_BYTES = 4_096;

    /** @var list<string> */
    private const SUPPORTED_RECORDS = [
        '001', '012', '022', '032', '042', '052', '062', '072', '092', '102', '112', '122', '132',
        '142', '152', '162', '182', '192', '202', '212', '222',
    ];

    public function decode(string $trf): TournamentState
    {
        if (strlen($trf) > self::MAX_BYTES) {
            throw new InvalidTournamentState('trf.too_large', 'TRF input exceeds 2 MiB.');
        }
        if (str_contains($trf, "\0")) {
            throw new InvalidTournamentState('trf.nul_byte', 'TRF input contains a NUL byte.');
        }

        $lines = preg_split('/\r\n|\n|\r/', $trf);
        if ($lines === false) {
            throw new InvalidTournamentState('trf.lines', 'Could not split TRF records.');
        }

        /** @var array<string, list<string>> $records */
        $records = [];
        foreach ($lines as $lineNumber => $line) {
            if ($line === '') {
                continue;
            }
            if (strlen($line) > self::MAX_LINE_BYTES) {
                throw new InvalidTournamentState('trf.line_too_long', sprintf('TRF line %d exceeds 4096 bytes.', $lineNumber + 1));
            }
            if (str_starts_with($line, '###')) {
                continue;
            }
            if (strlen($line) < 3) {
                throw new InvalidTournamentState('trf.record_short', sprintf('TRF line %d has no complete record identifier.', $lineNumber + 1));
            }

            $code = substr($line, 0, 3);
            if (!in_array($code, self::SUPPORTED_RECORDS, true)) {
                throw new InvalidTournamentState('trf.record_unsupported', sprintf('TRF record %s on line %d is outside the supported subset.', $code, $lineNumber + 1));
            }
            $records[$code] ??= [];
            $records[$code][] = $line;
        }

        foreach (['012', '142', '152', '192'] as $required) {
            if (count($records[$required] ?? []) !== 1) {
                throw new InvalidTournamentState('trf.record_required', sprintf('TRF requires exactly one %s record in this subset.', $required));
            }
        }
        if (($records['001'] ?? []) === []) {
            throw new InvalidTournamentState('trf.players_required', 'TRF requires at least one 001 player record.');
        }
        if (count($records['001']) > 256) {
            throw new InvalidTournamentState('players.limit', 'TRF player count exceeds 256.');
        }
        foreach (['022', '032', '042', '052', '062', '072', '092', '102', '122', '132', '162', '182', '202', '212', '222'] as $singleton) {
            if (count($records[$singleton] ?? []) > 1) {
                throw new InvalidTournamentState('trf.record_duplicate', sprintf('TRF contains more than one %s record.', $singleton));
            }
        }
        if (isset($records['202'], $records['212'])) {
            throw new InvalidTournamentState('trf.tiebreak_alternatives', 'TRF records 202 and 212 are mutually exclusive.');
        }

        $name = $this->recordValue($records['012'][0], '012');
        if ($name === '') {
            throw new InvalidTournamentState('trf.name_required', 'TRF tournament name is empty.');
        }
        $this->ascii($name, 'tournament name');
        $numberOfRounds = $this->positiveInteger($this->recordValue($records['142'][0], '142'), '142 number of rounds');
        if ($numberOfRounds > 256) {
            throw new InvalidTournamentState('rounds.limit', 'TRF number of rounds exceeds 256.');
        }

        $top = strtoupper($this->recordValue($records['152'][0], '152'));
        $topColour = match ($top) {
            'W' => Colour::White,
            'B' => Colour::Black,
            default => throw new InvalidTournamentState('trf.initial_colour', 'TRF 152 must contain W or B.'),
        };

        $type = strtoupper($this->recordValue($records['192'][0], '192'));
        if (!in_array($type, ['FIDE_DUTCH', 'FIDE_DUTCH_2025', 'FIDE_BURSTEIN', 'FIDE_ROUNDROBIN'], true)) {
            throw new InvalidTournamentState('trf.pairing_system', sprintf('TRF tournament type %s is not supported.', $type));
        }

        $scores = isset($records['162']) ? $this->parseScoring($records['162'][0]) : $this->defaultScoring();
        $city = $this->optionalRecordValue($records, '022');
        $federation = strtoupper($this->optionalRecordValue($records, '032'));
        if ($federation !== '' && preg_match('/^[A-Z]{3}$/D', $federation) !== 1) {
            throw new InvalidTournamentState('trf.federation', 'TRF 032 must contain a three-letter federation code.');
        }
        $startDate = $this->fullDate($this->optionalRecordValue($records, '042'), '042');
        $endDate = $this->fullDate($this->optionalRecordValue($records, '052'), '052');
        $tournamentType = $this->optionalRecordValue($records, '092');
        $chiefArbiter = $this->optionalRecordValue($records, '102');
        $deputyArbiters = array_map(fn (string $line): string => $this->recordValue($line, '112'), $records['112'] ?? []);
        $timeControlDescription = $this->optionalRecordValue($records, '122');
        $roundDates = isset($records['132']) ? $this->parseRoundDates($records['132'][0], $numberOfRounds) : [];
        $pairingController = $this->optionalRecordValue($records, '182');
        $tieBreaksRecord = isset($records['202']) ? '202' : (isset($records['212']) ? '212' : '');
        $tieBreaks = $tieBreaksRecord === '' ? '' : $this->recordValue($records[$tieBreaksRecord][0], $tieBreaksRecord);
        $encodedTimeControl = $this->optionalRecordValue($records, '222');
        foreach ([$city, $tournamentType, $chiefArbiter, ...$deputyArbiters, $timeControlDescription, $pairingController, $tieBreaks, $encodedTimeControl] as $metadataValue) {
            $this->ascii($metadataValue, 'TRF tournament metadata');
            if (strlen($metadataValue) > 512) {
                throw new InvalidTournamentState('trf.metadata_width', 'A TRF tournament metadata value exceeds 512 bytes.');
            }
        }
        $players = [];
        $pairingNumbers = [];
        $maxSeenRound = 0;
        foreach ($records['001'] as $line) {
            [$player, $seenRound] = $this->parsePlayer($line, $numberOfRounds);
            if (isset($pairingNumbers[$player->pairingNumber])) {
                throw new InvalidTournamentState('trf.player_duplicate', sprintf('Duplicate TRF starting rank %d.', $player->pairingNumber));
            }
            $pairingNumbers[$player->pairingNumber] = true;
            $players[] = $player;
            $maxSeenRound = max($maxSeenRound, $seenRound);
        }

        $known = array_fill_keys(array_map(static fn (Player $player): int => $player->pairingNumber, $players), true);
        foreach ($players as $player) {
            foreach ($player->results as $result) {
                if ($result['opponentId'] !== null && !isset($known[$result['opponentId']])) {
                    throw new InvalidTournamentState('trf.opponent_unknown', sprintf('Player %d references unknown opponent %d.', $player->pairingNumber, $result['opponentId']));
                }
            }
        }

        if (isset($records['062']) && $this->positiveInteger($this->recordValue($records['062'][0], '062'), '062 number of players') !== count($players)) {
            throw new InvalidTournamentState('trf.player_count', 'TRF 062 does not match the number of 001 records.');
        }
        $ratedPlayers = count(array_filter($players, static fn (Player $player): bool => $player->rating > 0));
        if (isset($records['072']) && $this->optionalInteger($this->recordValue($records['072'][0], '072'), '072 number of rated players') !== $ratedPlayers) {
            throw new InvalidTournamentState('trf.rated_player_count', 'TRF 072 does not match the rated 001 records.');
        }

        $this->validateResults($players, $scores);

        $state = new TournamentState(
            schemaVersion: TournamentState::SCHEMA_VERSION,
            round: $maxSeenRound + 1,
            numberOfRounds: $numberOfRounds,
            pairingSystem: match ($type) {
                'FIDE_BURSTEIN' => 'fide-burstein-2026',
                'FIDE_ROUNDROBIN' => 'fide-round-robin',
                default => 'fide-dutch-2026',
            },
            topColour: $topColour,
            pointsForWin: $scores['W'],
            pointsForDraw: $scores['D'],
            maxPlayers: 256,
            players: $players,
            tournamentName: $name,
            pointsForLoss: $scores['L'],
            pointsForZeroPointBye: $scores['A'],
            pointsForPairingAllocatedBye: $scores['P'],
            pointsForUnknown: $scores['X'],
            city: $city,
            federation: $federation,
            startDate: $startDate,
            endDate: $endDate,
            tournamentType: $tournamentType,
            chiefArbiter: $chiefArbiter,
            deputyArbiters: $deputyArbiters,
            timeControlDescription: $timeControlDescription,
            roundDates: $roundDates,
            pairingController: $pairingController,
            tieBreaksRecord: $tieBreaksRecord,
            tieBreaks: $tieBreaks,
            encodedTimeControl: $encodedTimeControl,
        );
        (new TournamentStateValidator())->validate($state);

        return $state;
    }

    public function encode(TournamentState $state): string
    {
        (new TournamentStateValidator())->validate($state);
        if (!in_array($state->pairingSystem, ['fide-dutch-2026', 'fide-burstein-2026', 'fide-round-robin'], true)) {
            throw new InvalidTournamentState('trf.pairing_system', 'Only FIDE Dutch 2026, FIDE Burstein 2026 and FIDE Round Robin can be written.');
        }
        if ($state->tournamentName === '' || strlen($state->tournamentName) > 512) {
            throw new InvalidTournamentState('trf.name_required', 'A non-empty tournament name of at most 512 bytes is required.');
        }
        $this->ascii($state->tournamentName, 'tournament name');

        $lines = ['012 '.$state->tournamentName];
        $this->appendOptionalRecord($lines, '022', $state->city);
        $this->appendOptionalRecord($lines, '032', strtoupper($state->federation));
        $this->appendOptionalRecord($lines, '042', $this->fullDate($state->startDate, '042'));
        $this->appendOptionalRecord($lines, '052', $this->fullDate($state->endDate, '052'));
        $ratedPlayers = count(array_filter($state->players, static fn (Player $player): bool => $player->rating > 0));
        $lines[] = '062 '.count($state->players);
        $lines[] = '072 '.$ratedPlayers;
        $this->appendOptionalRecord($lines, '092', $state->tournamentType);
        $this->appendOptionalRecord($lines, '102', $state->chiefArbiter);
        foreach ($state->deputyArbiters as $deputyArbiter) {
            $this->appendOptionalRecord($lines, '112', $deputyArbiter);
        }
        $this->appendOptionalRecord($lines, '122', $state->timeControlDescription);
        if ($state->roundDates !== []) {
            $lines[] = $this->writeRoundDates($state->roundDates, $state->numberOfRounds);
        }
        $lines = [...$lines,
            '142 '.$state->numberOfRounds,
            '152 '.($state->topColour === Colour::White ? 'W' : 'B'),
            sprintf(
                '162  W%4s    D%4s    L%4s    A%4s    P%4s    X%4s',
                $state->pointsForWin->toDecimal(1),
                $state->pointsForDraw->toDecimal(1),
                $state->lossScore()->toDecimal(1),
                $state->zeroPointByeScore()->toDecimal(1),
                $state->pairingAllocatedByeScore()->toDecimal(1),
                $state->unknownScore()->toDecimal(1),
            ),
        ];
        $this->appendOptionalRecord($lines, '182', $state->pairingController);
        $lines[] = '192 '.match ($state->pairingSystem) {
            'fide-burstein-2026' => 'FIDE_BURSTEIN',
            'fide-round-robin' => 'FIDE_ROUNDROBIN',
            default => 'FIDE_DUTCH_2025',
        };
        if ($state->tieBreaksRecord !== '') {
            if (!in_array($state->tieBreaksRecord, ['202', '212'], true) || $state->tieBreaks === '') {
                throw new InvalidTournamentState('trf.tiebreak_record', 'Tie-break metadata requires record 202 or 212 and a non-empty value.');
            }
            $this->appendOptionalRecord($lines, $state->tieBreaksRecord, $state->tieBreaks);
        } elseif ($state->tieBreaks !== '') {
            throw new InvalidTournamentState('trf.tiebreak_record', 'A tie-break value requires record 202 or 212.');
        }
        $this->appendOptionalRecord($lines, '222', $state->encodedTimeControl);

        $players = $state->players;
        usort($players, static fn (Player $left, Player $right): int => $left->pairingNumber <=> $right->pairingNumber);
        $this->validateResults($players, [
            'W' => $state->pointsForWin,
            'D' => $state->pointsForDraw,
            'L' => $state->lossScore(),
            'A' => $state->zeroPointByeScore(),
            'P' => $state->pairingAllocatedByeScore(),
            'X' => $state->unknownScore(),
        ]);
        $idToPairingNumber = [];
        foreach ($players as $player) {
            if (isset($idToPairingNumber[$player->id])) {
                throw new InvalidTournamentState('player.duplicate', 'Player IDs must be unique when writing TRF.');
            }
            $idToPairingNumber[$player->id] = $player->pairingNumber;
        }
        foreach ($players as $player) {
            $lines[] = $this->writePlayer($player, $idToPairingNumber);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /** @return array{W: Score, D: Score, L: Score, A: Score, P: Score, X: Score} */
    private function parseScoring(string $line): array
    {
        $positions = ['W' => 5, 'D' => 14, 'L' => 23, 'A' => 32, 'P' => 41, 'X' => 50];
        $scores = [];
        foreach ($positions as $symbol => $offset) {
            if (strtoupper(substr($line, $offset, 1)) !== $symbol) {
                throw new InvalidTournamentState('trf.scoring_alignment', sprintf('TRF 162 symbol %s is not at its required position.', $symbol));
            }
            try {
                $scores[$symbol] = Score::fromDecimal(substr($line, $offset + 1, 4));
            } catch (\InvalidArgumentException $exception) {
                throw new InvalidTournamentState('trf.scoring_value', sprintf('Invalid TRF 162 value for %s: %s', $symbol, $exception->getMessage()));
            }
        }

        /** @var array{W: Score, D: Score, L: Score, A: Score, P: Score, X: Score} $scores */
        return $scores;
    }

    /** @return array{W: Score, D: Score, L: Score, A: Score, P: Score, X: Score} */
    private function defaultScoring(): array
    {
        return [
            'W' => new Score(2, 2),
            'D' => new Score(1, 2),
            'L' => Score::zero(),
            'A' => Score::zero(),
            'P' => new Score(2, 2),
            'X' => new Score(1, 2),
        ];
    }

    /** @return array{Player, int} */
    private function parsePlayer(string $line, int $numberOfRounds): array
    {
        if (strlen($line) < 89) {
            throw new InvalidTournamentState('trf.player_short', 'TRF 001 record is shorter than 89 columns.');
        }
        $tpn = $this->positiveInteger($this->field($line, 4, 4), '001 starting rank');
        $rating = $this->optionalInteger($this->field($line, 48, 4), '001 rating');
        $fideId = $this->optionalInteger($this->field($line, 57, 11), '001 FIDE ID');
        $rank = $this->optionalInteger($this->field($line, 85, 4), '001 rank');
        $name = rtrim(substr($line, 14, 33));
        $title = trim(substr($line, 10, 3));
        $sex = strtolower(trim(substr($line, 9, 1)));
        $federation = trim(substr($line, 53, 3));
        $birth = trim(substr($line, 69, 10));
        if ($sex !== '' && !in_array($sex, ['m', 'w'], true)) {
            throw new InvalidTournamentState('trf.player_sex', sprintf('TRF player %d has unsupported sex code.', $tpn));
        }
        if ($birth !== '' && preg_match('/^\d{4}\/\d{2}\/\d{2}$/D', $birth) !== 1) {
            throw new InvalidTournamentState('trf.player_birth', sprintf('TRF player %d has invalid birth date.', $tpn));
        }
        try {
            $score = Score::fromDecimal($this->field($line, 80, 4));
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidTournamentState('trf.player_score', sprintf('TRF player %d has invalid score: %s', $tpn, $exception->getMessage()));
        }

        $results = [];
        $opponents = [];
        $colours = [];
        $floatHistory = [];
        $receivedBye = false;
        $maxSeenRound = 0;
        for ($round = 1; $round <= $numberOfRounds; ++$round) {
            $base = 89 + (($round - 1) * 10);
            $block = substr($line, $base, 10);
            if ($block === '' || trim($block) === '') {
                continue;
            }
            if (strlen($block) < 10) {
                throw new InvalidTournamentState('trf.round_short', sprintf('TRF player %d round %d block is incomplete.', $tpn, $round));
            }
            $opponent = $this->optionalInteger(substr($block, 2, 4), sprintf('001 player %d round %d opponent', $tpn, $round));
            $colourCode = strtolower(substr($block, 7, 1));
            if (!in_array($colourCode, ['w', 'b', '-', ' '], true)) {
                throw new InvalidTournamentState('trf.round_colour', sprintf('TRF player %d round %d has invalid colour.', $tpn, $round));
            }
            $resultCode = strtoupper(substr($block, 9, 1));
            if (!in_array($resultCode, ['-', '+', 'W', 'D', 'L', '1', '=', '0', 'H', 'F', 'U', 'Z'], true)) {
                throw new InvalidTournamentState('trf.round_result', sprintf('TRF player %d round %d has invalid result.', $tpn, $round));
            }
            $played = in_array($resultCode, ['W', 'D', 'L', '1', '=', '0'], true);
            $colour = match ($colourCode) {
                'w' => Colour::White,
                'b' => Colour::Black,
                default => null,
            };
            $results[] = [
                'round' => $round,
                'opponentId' => $opponent > 0 ? $opponent : null,
                'colour' => $colour?->value,
                'result' => $resultCode,
                'played' => $played,
            ];
            if ($played && $opponent > 0) {
                $opponents[] = $opponent;
            }
            if ($played && $colour !== null) {
                $colours[] = $colour;
            }
            if ($resultCode === 'U') {
                $receivedBye = true;
            }
            $floatHistory[] = 'none';
            $maxSeenRound = $round;
        }

        return [new Player(
            id: $tpn,
            pairingNumber: $tpn,
            name: $name,
            rating: $rating,
            title: $title,
            team: '',
            club: '',
            active: true,
            score: $score,
            opponentHistory: $opponents,
            colourHistory: $colours,
            results: $results,
            receivedPairingAllocatedBye: $receivedBye,
            floatHistory: $floatHistory,
            acceleration: null,
            sex: $sex,
            federation: $federation,
            fideId: $fideId,
            birthDate: $birth,
            rank: $rank,
        ), $maxSeenRound];
    }

    /** @param array<int, int> $idToPairingNumber */
    private function writePlayer(Player $player, array $idToPairingNumber): string
    {
        foreach ([$player->name, $player->title, $player->sex, $player->federation, $player->birthDate] as $value) {
            $this->ascii($value, sprintf('player %d field', $player->pairingNumber));
        }
        if (strlen($player->name) > 33 || strlen($player->title) > 3 || strlen($player->federation) > 3) {
            throw new InvalidTournamentState('trf.player_width', sprintf('Player %d contains a field wider than TRF 001 supports.', $player->pairingNumber));
        }
        if ($player->sex !== '' && !in_array(strtolower($player->sex), ['m', 'w'], true)) {
            throw new InvalidTournamentState('trf.player_sex', sprintf('Player %d has unsupported sex code.', $player->pairingNumber));
        }

        $line = sprintf(
            '001 %4d %1s%3s %-33s %4s %-3s %11s %10s %4s %4d',
            $player->pairingNumber,
            strtolower($player->sex),
            $player->title,
            $player->name,
            $player->rating > 0 ? (string) $player->rating : '',
            $player->federation,
            $player->fideId > 0 ? (string) $player->fideId : '',
            $player->birthDate,
            $player->score->toDecimal(1),
            $player->rank > 0 ? $player->rank : $player->pairingNumber,
        );

        $byRound = [];
        foreach ($player->results as $result) {
            $byRound[$result['round']] = $result;
        }
        $lastRound = $byRound === [] ? 0 : max(array_keys($byRound));
        for ($round = 1; $round <= $lastRound; ++$round) {
            if (!isset($byRound[$round])) {
                $line .= str_repeat(' ', 10);
                continue;
            }
            $result = $byRound[$round];
            $opponent = $result['opponentId'] === null ? 0 : ($idToPairingNumber[$result['opponentId']] ?? throw new InvalidTournamentState('trf.opponent_unknown', sprintf('Player %d references unknown opponent ID %d.', $player->pairingNumber, $result['opponentId'])));
            $colour = match ($result['colour']) {
                Colour::White->value => 'w',
                Colour::Black->value => 'b',
                null => '-',
                default => throw new InvalidTournamentState('trf.round_colour', sprintf('Player %d round %d has unsupported colour.', $player->pairingNumber, $round)),
            };
            $resultCode = strtoupper($result['result']);
            if (!in_array($resultCode, ['-', '+', 'W', 'D', 'L', '1', '=', '0', 'H', 'F', 'U', 'Z'], true)) {
                throw new InvalidTournamentState('trf.round_result', sprintf('Player %d round %d has unsupported result.', $player->pairingNumber, $round));
            }
            $line .= sprintf('  %4d %s %s', $opponent, $colour, $resultCode);
        }

        return $line;
    }

    private function recordValue(string $line, string $code): string
    {
        if (!str_starts_with($line, $code) || strlen($line) < 5 || $line[3] !== ' ') {
            throw new InvalidTournamentState('trf.record_format', sprintf('TRF %s record must use a space after its identifier.', $code));
        }
        return trim(substr($line, 4));
    }

    private function field(string $line, int $offset, int $length): string
    {
        return trim(substr($line, $offset, $length));
    }

    private function positiveInteger(string $value, string $path): int
    {
        $integer = $this->optionalInteger($value, $path);
        if ($integer < 1) {
            throw new InvalidTournamentState('trf.integer_positive', "$path must be a positive integer.");
        }
        return $integer;
    }

    private function optionalInteger(string $value, string $path): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/D', $value) !== 1) {
            throw new InvalidTournamentState('trf.integer', "$path must contain decimal digits only.");
        }
        return (int) $value;
    }

    private function ascii(string $value, string $path): void
    {
        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            throw new InvalidTournamentState('trf.ascii_subset', "$path contains characters outside the supported ASCII subset.");
        }
    }

    /** @param array<array-key, list<string>> $records */
    private function optionalRecordValue(array $records, string $code): string
    {
        return isset($records[$code]) ? $this->recordValue($records[$code][0], $code) : '';
    }

    private function fullDate(string $value, string $code): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/D', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidTournamentState('trf.date', sprintf('TRF %s must use a valid YYYY/MM/DD date.', $code));
        }

        return $value;
    }

    /** @return list<string> */
    private function parseRoundDates(string $line, int $numberOfRounds): array
    {
        $dates = [];
        for ($round = 1; $round <= $numberOfRounds; ++$round) {
            $date = trim(substr($line, 91 + (($round - 1) * 10), 8));
            if ($date !== '' && preg_match('/^\d{2}\/\d{2}\/\d{2}$/D', $date) !== 1) {
                throw new InvalidTournamentState('trf.round_date', sprintf('TRF 132 round %d must use YY/MM/DD.', $round));
            }
            $dates[] = $date;
        }
        while ($dates !== [] && end($dates) === '') {
            array_pop($dates);
        }

        return $dates;
    }

    /** @param list<string> $dates */
    private function writeRoundDates(array $dates, int $numberOfRounds): string
    {
        if (count($dates) > $numberOfRounds) {
            throw new InvalidTournamentState('trf.round_dates_count', 'There are more round dates than tournament rounds.');
        }
        $line = '132'.str_repeat(' ', 88);
        foreach ($dates as $roundIndex => $date) {
            if ($date !== '' && preg_match('/^\d{2}\/\d{2}\/\d{2}$/D', $date) !== 1) {
                throw new InvalidTournamentState('trf.round_date', sprintf('Round date %d must use YY/MM/DD.', $roundIndex + 1));
            }
            $line = str_pad($line, 91 + ($roundIndex * 10), ' ');
            $line .= str_pad($date, 8).'  ';
        }

        return rtrim($line);
    }

    /** @param list<string> $lines */
    private function appendOptionalRecord(array &$lines, string $code, string $value): void
    {
        if ($value === '') {
            return;
        }
        $this->ascii($value, "TRF $code");
        if (strlen($value) > 512) {
            throw new InvalidTournamentState('trf.metadata_width', "TRF $code exceeds 512 bytes.");
        }
        $lines[] = $code.' '.$value;
    }

    /**
     * @param list<Player> $players
     * @param array{W: Score, D: Score, L: Score, A: Score, P: Score, X: Score} $scores
     */
    private function validateResults(array $players, array $scores): void
    {
        /** @var array<int, Player> $byId */
        $byId = [];
        /** @var array<int, array<int, array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool}>> $byRound */
        $byRound = [];
        foreach ($players as $player) {
            if (isset($byId[$player->id])) {
                throw new InvalidTournamentState('trf.player_id_duplicate', sprintf('Duplicate player ID %d.', $player->id));
            }
            $byId[$player->id] = $player;
            foreach ($player->results as $result) {
                $round = $result['round'];
                if ($round < 1) {
                    throw new InvalidTournamentState('trf.round_number', sprintf('Player %d has an invalid round number.', $player->pairingNumber));
                }
                if (isset($byRound[$player->id][$round])) {
                    throw new InvalidTournamentState('trf.round_duplicate', sprintf('Player %d has duplicate round %d.', $player->pairingNumber, $round));
                }
                $byRound[$player->id][$round] = $result;
            }
        }

        foreach ($players as $player) {
            $calculated = Score::zero();
            foreach ($byRound[$player->id] ?? [] as $round => $result) {
                $code = strtoupper($result['result']);
                $opponentId = $result['opponentId'];
                $colour = $result['colour'];

                if ($opponentId === null) {
                    if (!in_array($code, ['H', 'F', 'U', 'Z'], true) || $colour !== null) {
                        throw new InvalidTournamentState('trf.bye_invalid', sprintf('Player %d round %d has an invalid bye record.', $player->pairingNumber, $round));
                    }
                } else {
                    if (!isset($byId[$opponentId])) {
                        throw new InvalidTournamentState('trf.opponent_unknown', sprintf('Player %d references unknown opponent ID %d.', $player->pairingNumber, $opponentId));
                    }
                    if (in_array($code, ['H', 'F', 'U', 'Z'], true)) {
                        throw new InvalidTournamentState('trf.bye_opponent', sprintf('Player %d round %d combines a bye result with an opponent.', $player->pairingNumber, $round));
                    }
                    $reciprocal = $byRound[$opponentId][$round] ?? null;
                    if ($reciprocal === null || $reciprocal['opponentId'] !== $player->id) {
                        throw new InvalidTournamentState('trf.result_not_reciprocal', sprintf('Player %d round %d has no reciprocal opponent record.', $player->pairingNumber, $round));
                    }
                    $expected = match ($code) {
                        '1', 'W' => ['0', 'L'],
                        '0', 'L' => ['1', 'W'],
                        '=', 'D' => ['=', 'D'],
                        '+' => ['-'],
                        '-' => ['+'],
                        default => throw new InvalidTournamentState('trf.round_result', sprintf('Player %d round %d has unsupported result.', $player->pairingNumber, $round)),
                    };
                    if (!in_array(strtoupper($reciprocal['result']), $expected, true)) {
                        throw new InvalidTournamentState('trf.result_incompatible', sprintf('Players %d and %d have incompatible results in round %d.', $player->pairingNumber, $byId[$opponentId]->pairingNumber, $round));
                    }
                    if (in_array($code, ['1', 'W', '0', 'L', '=', 'D'], true)) {
                        if ($colour === null || $reciprocal['colour'] === null || $colour === $reciprocal['colour']) {
                            throw new InvalidTournamentState('trf.colour_incompatible', sprintf('Players %d and %d have incompatible colours in round %d.', $player->pairingNumber, $byId[$opponentId]->pairingNumber, $round));
                        }
                    } elseif ($colour !== null || $reciprocal['colour'] !== null) {
                        throw new InvalidTournamentState('trf.forfeit_colour', sprintf('Players %d and %d must not have colours for an unplayed game in round %d.', $player->pairingNumber, $byId[$opponentId]->pairingNumber, $round));
                    }
                }

                $calculated = $calculated->add(match ($code) {
                    '1', 'W', '+', 'F' => $scores['W'],
                    '=', 'D', 'H' => $scores['D'],
                    '0', 'L' => $scores['L'],
                    '-', 'Z' => $scores['A'],
                    'U' => $scores['P'],
                });
            }

            if ($calculated->compare($player->score) !== 0) {
                throw new InvalidTournamentState('trf.score_mismatch', sprintf('Player %d score does not equal the sum of round results.', $player->pairingNumber));
            }
        }
    }
}
