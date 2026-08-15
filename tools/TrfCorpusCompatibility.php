<?php

declare(strict_types=1);

final class TrfCorpusCompatibility
{
    /** @return array{string, list<string>, array{source: string, colour: string, basis: array{pairingNumber: int, rankIndex: int, recordedColour: string}|null}} */
    public static function normalize(string $trf): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $trf);
        if ($lines === false) {
            throw new \UnexpectedValueException('TRF records could not be split into lines.');
        }

        $transformations = [];
        $has152 = false;
        $has192 = false;
        $record152Colour = '';
        foreach ($lines as &$line) {
            $code = substr($line, 0, 3);
            $has152 = $has152 || $code === '152';
            $has192 = $has192 || $code === '192';
            if ($code === '152') {
                $record152Colour = strtoupper(trim(substr($line, 3)));
            }

            if (in_array($code, ['022', '032', '042', '052', '062', '072', '092', '102', '112', '122', '132', '162', '182', '202', '212', '222'], true)
                && trim(substr($line, 3)) === '') {
                $line = '';
                $transformations[] = 'drop-empty-optional-record';
                continue;
            }
            if (in_array($code, ['042', '052'], true)
                && preg_match('/^(04[2]|052) (\d{2}\/\d{2}\/\d{2})$/D', $line, $dateParts) === 1) {
                $line = $dateParts[1].' 20'.$dateParts[2];
                $transformations[] = 'expand-two-digit-year';
                continue;
            }
            if ($code === '001' && strlen($line) > 9 && strtolower($line[9]) === 'u') {
                $line[9] = ' ';
                $transformations[] = 'clear-legacy-unknown-sex';
            }
            if ($code === '001') {
                for ($base = 89; $base + 9 < strlen($line); $base += 10) {
                    if (in_array($line[$base + 9], ['+', '-'], true) && in_array(strtolower($line[$base + 7]), ['w', 'b'], true)) {
                        $line[$base + 7] = '-';
                        $transformations[] = 'clear-legacy-forfeit-colour';
                    }
                }
            }
        }
        unset($line);

        if (!$has152) {
            $inference = self::inferTopColourFromRoundOne($lines);
            if ($inference === null) {
                throw new \UnexpectedValueException('TRF 152 is missing and the initial colour cannot be inferred from a played round-one game.');
            }
            $lines[] = '152 '.$inference['colour'];
            $transformations[] = 'infer-top-colour-from-round-one';
            $topColourProvenance = [
                'source' => 'inferred-round-one',
                'colour' => $inference['colour'],
                'basis' => $inference['basis'],
            ];
        } else {
            $topColourProvenance = [
                'source' => 'record-152',
                'colour' => $record152Colour,
                'basis' => null,
            ];
        }
        if (!$has192) {
            $lines[] = '192 FIDE_DUTCH_2025';
            $transformations[] = 'default-pairing-system-dutch';
        }

        return [implode("\n", $lines), array_values(array_unique($transformations)), $topColourProvenance];
    }

    /**
     * @param list<string> $lines
     * @return array{colour: string, basis: array{pairingNumber: int, rankIndex: int, recordedColour: string}}|null
     */
    private static function inferTopColourFromRoundOne(array $lines): ?array
    {
        $participants = [];
        $playedColours = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, '001') || strlen($line) < 99) {
                continue;
            }
            $pairingNumber = filter_var(trim(substr($line, 4, 4)), FILTER_VALIDATE_INT);
            if (!is_int($pairingNumber) || $pairingNumber < 1) {
                continue;
            }
            $block = substr($line, 89, 10);
            $opponent = filter_var(trim(substr($block, 2, 4)), FILTER_VALIDATE_INT);
            $result = strtoupper(substr($block, 9, 1));
            if ((is_int($opponent) && $opponent > 0) || in_array($result, ['U', '+'], true)) {
                $participants[$pairingNumber] = true;
            }
            $colour = strtoupper(substr($block, 7, 1));
            if (is_int($opponent) && $opponent > 0
                && in_array($result, ['W', 'D', 'L', '1', '=', '0'], true)
                && in_array($colour, ['W', 'B'], true)) {
                $playedColours[$pairingNumber] = $colour;
            }
        }
        if ($participants === [] || $playedColours === []) {
            return null;
        }

        $participantNumbers = array_keys($participants);
        sort($participantNumbers, SORT_NUMERIC);
        ksort($playedColours, SORT_NUMERIC);
        foreach ($playedColours as $pairingNumber => $colour) {
            $rankIndex = array_search($pairingNumber, $participantNumbers, true);
            if (!is_int($rankIndex)) {
                continue;
            }

            $topColour = ($rankIndex & 1) === 0
                ? $colour
                : ($colour === 'W' ? 'B' : 'W');

            return [
                'colour' => $topColour,
                'basis' => [
                    'pairingNumber' => $pairingNumber,
                    'rankIndex' => $rankIndex,
                    'recordedColour' => $colour,
                ],
            ];
        }

        return null;
    }
}
