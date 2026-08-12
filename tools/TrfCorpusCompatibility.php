<?php

declare(strict_types=1);

final class TrfCorpusCompatibility
{
    /** @return array{string, list<string>} */
    public static function normalize(string $trf): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $trf);
        if ($lines === false) {
            return [$trf, []];
        }

        $transformations = [];
        $has152 = false;
        $has192 = false;
        foreach ($lines as &$line) {
            $code = substr($line, 0, 3);
            $has152 = $has152 || $code === '152';
            $has192 = $has192 || $code === '192';

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
            $lines[] = '152 W';
            $transformations[] = 'default-top-colour-white';
        }
        if (!$has192) {
            $lines[] = '192 FIDE_DUTCH_2025';
            $transformations[] = 'default-pairing-system-dutch';
        }

        return [implode("\n", $lines), array_values(array_unique($transformations))];
    }
}
