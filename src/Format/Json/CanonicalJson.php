<?php

declare(strict_types=1);

namespace OpenPairing\Format\Json;

use JsonException;
use OpenPairing\Domain\Colour\Colour;
use OpenPairing\Domain\Score\Score;
use OpenPairing\Domain\Tournament\InvalidTournamentState;
use OpenPairing\Domain\Tournament\Player;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Validation\TournamentStateValidator;

final class CanonicalJson
{
    public const MAX_BYTES = 1_048_576;

    public function decode(string $json): TournamentState
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidTournamentState('json.too_large', 'Canonical JSON exceeds 1 MiB.');
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidTournamentState('json.invalid', $exception->getMessage());
        }

        $data = $this->objectValue($data, 'Canonical JSON root');
        $this->onlyKeys($data, [
            'schema', 'tournamentName', 'round', 'numberOfRounds', 'pairingSystem',
            'configuration', 'scoring', 'metadata', 'players',
        ], 'Canonical JSON root');
        $this->requireKeys($data, ['schema', 'round', 'numberOfRounds', 'pairingSystem', 'configuration', 'scoring', 'players'], 'Canonical JSON root');

        $schema = $this->string($data, 'schema');
        if ($schema !== TournamentState::SCHEMA_VERSION) {
            throw new InvalidTournamentState('schema.unsupported', sprintf('Unsupported schema "%s".', $schema));
        }

        $configuration = $this->object($data, 'configuration');
        $scoring = $this->object($data, 'scoring');
        $metadata = isset($data['metadata']) ? $this->object($data, 'metadata') : [];
        $this->onlyKeys($configuration, ['topColour', 'maxPlayers'], 'configuration');
        $this->onlyKeys($scoring, ['win', 'draw', 'loss', 'zeroPointBye', 'pairingAllocatedBye', 'unknown'], 'scoring');
        $this->requireKeys($configuration, ['topColour', 'maxPlayers'], 'configuration');
        $this->requireKeys($scoring, ['win', 'draw'], 'scoring');
        $this->onlyKeys($metadata, [
            'city', 'federation', 'startDate', 'endDate', 'tournamentType', 'chiefArbiter',
            'deputyArbiters', 'timeControlDescription', 'roundDates', 'pairingController',
            'tieBreaksRecord', 'tieBreaks', 'encodedTimeControl',
        ], 'metadata');
        $rawPlayers = $data['players'] ?? null;
        if (!is_array($rawPlayers) || !array_is_list($rawPlayers)) {
            throw new InvalidTournamentState('players.type', 'players must be a JSON array.');
        }

        $maxPlayers = $this->integer($configuration, 'maxPlayers');
        if ($maxPlayers < 0 || $maxPlayers > 256 || count($rawPlayers) > $maxPlayers) {
            throw new InvalidTournamentState('players.limit', 'Player count or maxPlayers is outside 0..256.');
        }

        $players = [];
        foreach ($rawPlayers as $index => $rawPlayer) {
            $players[] = $this->player($this->objectValue($rawPlayer, sprintf('players[%d]', $index)), $index);
        }

        $topColour = Colour::tryFrom($this->string($configuration, 'topColour'));
        if ($topColour === null) {
            throw new InvalidTournamentState('colour.invalid', 'topColour must be white or black.');
        }

        $state = new TournamentState(
            schemaVersion: $schema,
            round: $this->integer($data, 'round'),
            numberOfRounds: $this->integer($data, 'numberOfRounds'),
            pairingSystem: $this->string($data, 'pairingSystem'),
            topColour: $topColour,
            pointsForWin: $this->score($this->object($scoring, 'win'), 'scoring.win'),
            pointsForDraw: $this->score($this->object($scoring, 'draw'), 'scoring.draw'),
            maxPlayers: $maxPlayers,
            players: $players,
            tournamentName: isset($data['tournamentName']) ? $this->string($data, 'tournamentName') : '',
            pointsForLoss: isset($scoring['loss']) ? $this->score($this->object($scoring, 'loss'), 'scoring.loss') : Score::zero(),
            pointsForZeroPointBye: isset($scoring['zeroPointBye']) ? $this->score($this->object($scoring, 'zeroPointBye'), 'scoring.zeroPointBye') : Score::zero(),
            pointsForPairingAllocatedBye: isset($scoring['pairingAllocatedBye']) ? $this->score($this->object($scoring, 'pairingAllocatedBye'), 'scoring.pairingAllocatedBye') : null,
            pointsForUnknown: isset($scoring['unknown']) ? $this->score($this->object($scoring, 'unknown'), 'scoring.unknown') : null,
            city: isset($metadata['city']) ? $this->string($metadata, 'city') : '',
            federation: isset($metadata['federation']) ? $this->string($metadata, 'federation') : '',
            startDate: isset($metadata['startDate']) ? $this->string($metadata, 'startDate') : '',
            endDate: isset($metadata['endDate']) ? $this->string($metadata, 'endDate') : '',
            tournamentType: isset($metadata['tournamentType']) ? $this->string($metadata, 'tournamentType') : '',
            chiefArbiter: isset($metadata['chiefArbiter']) ? $this->string($metadata, 'chiefArbiter') : '',
            deputyArbiters: isset($metadata['deputyArbiters']) ? $this->stringList($metadata['deputyArbiters'], 'metadata.deputyArbiters') : [],
            timeControlDescription: isset($metadata['timeControlDescription']) ? $this->string($metadata, 'timeControlDescription') : '',
            roundDates: isset($metadata['roundDates']) ? $this->stringList($metadata['roundDates'], 'metadata.roundDates') : [],
            pairingController: isset($metadata['pairingController']) ? $this->string($metadata, 'pairingController') : '',
            tieBreaksRecord: isset($metadata['tieBreaksRecord']) ? $this->string($metadata, 'tieBreaksRecord') : '',
            tieBreaks: isset($metadata['tieBreaks']) ? $this->string($metadata, 'tieBreaks') : '',
            encodedTimeControl: isset($metadata['encodedTimeControl']) ? $this->string($metadata, 'encodedTimeControl') : '',
        );
        (new TournamentStateValidator())->validate($state);

        return $state;
    }

    public function encode(TournamentState $state, bool $pretty = false): string
    {
        (new TournamentStateValidator())->validate($state);
        $players = $state->players;
        usort(
            $players,
            static fn (Player $left, Player $right): int => $left->pairingNumber <=> $right->pairingNumber,
        );

        $data = [
            'schema' => $state->schemaVersion,
            'tournamentName' => $state->tournamentName,
            'round' => $state->round,
            'numberOfRounds' => $state->numberOfRounds,
            'pairingSystem' => $state->pairingSystem,
            'configuration' => [
                'topColour' => $state->topColour->value,
                'maxPlayers' => $state->maxPlayers,
            ],
            'scoring' => [
                'win' => $state->pointsForWin->toArray(),
                'draw' => $state->pointsForDraw->toArray(),
                'loss' => $state->lossScore()->toArray(),
                'zeroPointBye' => $state->zeroPointByeScore()->toArray(),
                'pairingAllocatedBye' => $state->pairingAllocatedByeScore()->toArray(),
                'unknown' => $state->unknownScore()->toArray(),
            ],
            'metadata' => [
                'city' => $state->city,
                'federation' => $state->federation,
                'startDate' => $state->startDate,
                'endDate' => $state->endDate,
                'tournamentType' => $state->tournamentType,
                'chiefArbiter' => $state->chiefArbiter,
                'deputyArbiters' => $state->deputyArbiters,
                'timeControlDescription' => $state->timeControlDescription,
                'roundDates' => $state->roundDates,
                'pairingController' => $state->pairingController,
                'tieBreaksRecord' => $state->tieBreaksRecord,
                'tieBreaks' => $state->tieBreaks,
                'encodedTimeControl' => $state->encodedTimeControl,
            ],
            'players' => array_map($this->encodePlayer(...), $players),
        ];

        try {
            return json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0),
                32,
            ).($pretty ? PHP_EOL : '');
        } catch (JsonException $exception) {
            throw new InvalidTournamentState('json.encode', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $data */
    private function player(array $data, int $index): Player
    {
        $this->onlyKeys($data, [
            'id', 'pairingNumber', 'name', 'rating', 'title', 'team', 'club', 'active',
            'score', 'opponentHistory', 'colourHistory', 'results',
            'receivedPairingAllocatedBye', 'floatHistory', 'acceleration', 'sex',
            'federation', 'fideId', 'birthDate', 'rank',
        ], "players[$index]");
        $this->requireKeys($data, [
            'id', 'pairingNumber', 'name', 'rating', 'title', 'team', 'club', 'active',
            'score', 'opponentHistory', 'colourHistory', 'results',
            'receivedPairingAllocatedBye', 'floatHistory', 'acceleration',
        ], "players[$index]");
        $opponents = $this->integerList($data['opponentHistory'] ?? [], "players[$index].opponentHistory");
        $colours = [];
        $rawColours = $data['colourHistory'] ?? [];
        if (!is_array($rawColours) || !array_is_list($rawColours)) {
            throw new InvalidTournamentState('colour_history.type', "players[$index].colourHistory must be an array.");
        }
        if (count($rawColours) > 256) {
            throw new InvalidTournamentState('colour_history.limit', "players[$index].colourHistory exceeds 256 entries.");
        }
        foreach ($rawColours as $rawColour) {
            if ($rawColour === null) {
                $colours[] = null;
                continue;
            }
            if (!is_string($rawColour) || Colour::tryFrom($rawColour) === null) {
                throw new InvalidTournamentState('colour_history.value', "players[$index] has an invalid colour history value.");
            }
            $colours[] = Colour::from($rawColour);
        }

        $results = $data['results'] ?? [];
        if (!is_array($results) || !array_is_list($results)) {
            throw new InvalidTournamentState('results.type', "players[$index].results must be an array.");
        }
        if (count($results) > 256) {
            throw new InvalidTournamentState('results.limit', "players[$index].results exceeds 256 entries.");
        }

        $rawFloatHistory = $data['floatHistory'] ?? [];
        if (!is_array($rawFloatHistory) || !array_is_list($rawFloatHistory)) {
            throw new InvalidTournamentState('floats.type', "players[$index].floatHistory must be an array.");
        }
        if (count($rawFloatHistory) > 256) {
            throw new InvalidTournamentState('floats.limit', "players[$index].floatHistory exceeds 256 entries.");
        }
        $floatHistory = [];
        foreach ($rawFloatHistory as $float) {
            if (!is_string($float) || !in_array($float, ['up', 'down', 'none'], true)) {
                throw new InvalidTournamentState('floats.value', "players[$index] has an invalid float value.");
            }
            $floatHistory[] = $float;
        }

        $score = $this->score($this->object($data, 'score'), "players[$index].score");
        $acceleration = null;
        if (isset($data['acceleration'])) {
            $acceleration = $this->score(
                $this->objectValue($data['acceleration'], "players[$index].acceleration"),
                "players[$index].acceleration",
            );
        }

        /** @var list<array{round: int, opponentId: int|null, colour: string|null, result: string, played: bool}> $typedResults */
        $typedResults = [];
        foreach ($results as $resultIndex => $result) {
            $result = $this->objectValue($result, "players[$index].results[$resultIndex]");
            $this->onlyKeys($result, ['round', 'opponentId', 'colour', 'result', 'played'], "players[$index].results[$resultIndex]");
            $this->requireKeys($result, ['round', 'opponentId', 'colour', 'result', 'played'], "players[$index].results[$resultIndex]");
            $typedResults[] = [
                'round' => $this->integer($result, 'round'),
                'opponentId' => isset($result['opponentId']) ? $this->integer($result, 'opponentId') : null,
                'colour' => isset($result['colour']) ? $this->string($result, 'colour') : null,
                'result' => $this->string($result, 'result'),
                'played' => $this->boolean($result, 'played'),
            ];
        }

        return new Player(
            id: $this->integer($data, 'id'),
            pairingNumber: $this->integer($data, 'pairingNumber'),
            name: $this->boundedString($data, 'name', 512),
            rating: $this->integer($data, 'rating'),
            title: $this->boundedString($data, 'title', 16),
            team: $this->boundedString($data, 'team', 512),
            club: $this->boundedString($data, 'club', 512),
            active: $this->boolean($data, 'active'),
            score: $score,
            opponentHistory: $opponents,
            colourHistory: $colours,
            results: $typedResults,
            receivedPairingAllocatedBye: $this->boolean($data, 'receivedPairingAllocatedBye'),
            floatHistory: $floatHistory,
            acceleration: $acceleration,
            sex: isset($data['sex']) ? $this->boundedString($data, 'sex', 1) : '',
            federation: isset($data['federation']) ? $this->boundedString($data, 'federation', 3) : '',
            fideId: isset($data['fideId']) ? $this->integer($data, 'fideId') : 0,
            birthDate: isset($data['birthDate']) ? $this->boundedString($data, 'birthDate', 10) : '',
            rank: isset($data['rank']) ? $this->integer($data, 'rank') : 0,
        );
    }

    /** @return array<string, mixed> */
    private function encodePlayer(Player $player): array
    {
        return [
            'id' => $player->id,
            'pairingNumber' => $player->pairingNumber,
            'name' => $player->name,
            'rating' => $player->rating,
            'title' => $player->title,
            'team' => $player->team,
            'club' => $player->club,
            'active' => $player->active,
            'score' => $player->score->toArray(),
            'opponentHistory' => $player->opponentHistory,
            'colourHistory' => array_map(
                static fn (?Colour $colour): ?string => $colour?->value,
                $player->colourHistory,
            ),
            'results' => $player->results,
            'receivedPairingAllocatedBye' => $player->receivedPairingAllocatedBye,
            'floatHistory' => $player->floatHistory,
            'acceleration' => $player->acceleration?->toArray(),
            'sex' => $player->sex,
            'federation' => $player->federation,
            'fideId' => $player->fideId,
            'birthDate' => $player->birthDate,
            'rank' => $player->rank,
        ];
    }

    /** @param array<string, mixed> $data */
    private function score(array $data, string $path): Score
    {
        $this->onlyKeys($data, ['units', 'scale'], $path);
        $this->requireKeys($data, ['units', 'scale'], $path);
        try {
            return new Score($this->integer($data, 'units'), $this->integer($data, 'scale'));
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidTournamentState('score.range', "$path: {$exception->getMessage()}");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function object(array $data, string $key): array
    {
        return $this->objectValue($data[$key] ?? null, $key);
    }

    /** @return array<string, mixed> */
    private function objectValue(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidTournamentState('json.object', "$path must be an object.");
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidTournamentState('json.object_key', "$path contains a non-string object key.");
            }
            $object[$key] = $item;
        }
        return $object;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidTournamentState('json.string', "$key must be a string.");
        }
        if (strlen($value) > 512) {
            throw new InvalidTournamentState('json.string_limit', "$key exceeds 512 bytes.");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function boundedString(array $data, string $key, int $maximumBytes): string
    {
        $value = $this->string($data, $key);
        if (strlen($value) > $maximumBytes) {
            throw new InvalidTournamentState('json.string_limit', sprintf('%s exceeds %d bytes.', $key, $maximumBytes));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidTournamentState('json.integer', "$key must be an integer.");
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidTournamentState('json.boolean', "$key must be a boolean.");
        }
        return $value;
    }

    /** @return list<int> */
    private function integerList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidTournamentState('json.integer_list', "$path must be an array.");
        }
        if (count($value) > 256) {
            throw new InvalidTournamentState('json.list_limit', "$path exceeds 256 entries.");
        }
        $integers = [];
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new InvalidTournamentState('json.integer_list', "$path must contain only integers.");
            }
            $integers[] = $item;
        }
        return $integers;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidTournamentState('json.string_list', "$path must be an array.");
        }
        if (count($value) > 256) {
            throw new InvalidTournamentState('json.list_limit', "$path exceeds 256 entries.");
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidTournamentState('json.string_list', "$path must contain only strings.");
            }
            if (strlen($item) > 512) {
                throw new InvalidTournamentState('json.string_limit', "$path contains a string over 512 bytes.");
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private function onlyKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidTournamentState('json.property_unknown', sprintf('%s contains unknown property "%s".', $path, $key));
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $required
     */
    private function requireKeys(array $data, array $required, string $path): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new InvalidTournamentState('json.property_required', sprintf('%s is missing required property "%s".', $path, $key));
            }
        }
    }
}
