<?php

declare(strict_types=1);

namespace OpenPairing\Development\Oracle;

use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Format\Trf\Trf2026Codec;

final readonly class BbpOracleAdapter
{
    public function __construct(
        private string $executable,
        private string $wslDistribution = 'Ubuntu',
        private int $timeoutMilliseconds = 10_000,
    ) {
    }

    public function pair(TournamentState $state): OracleResult
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'openpairing-bbp-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            return $this->infrastructure('Could not create an isolated temporary directory.');
        }
        $input = $directory.DIRECTORY_SEPARATOR.'input.trf';
        $output = $directory.DIRECTORY_SEPARATOR.'pairs.txt';
        try {
            $trf = (new Trf2026Codec())->encode($state);
            $trf = preg_replace('/    X.{4}\r\n/D', "\r\n", $trf, 1, $xFieldsRemoved);
            if (!is_string($trf) || $xFieldsRemoved !== 1) {
                return $this->infrastructure('Could not create the documented BBP-compatible 162 record.');
            }
            $trf = str_replace('     0 - ', '  0000 - ', $trf);
            if (file_put_contents($input, $trf) === false) {
                return $this->infrastructure('Could not write BBP input.');
            }
            if (preg_match('/^[A-Za-z0-9._-]+$/D', $this->wslDistribution) !== 1) {
                return $this->infrastructure('The WSL distribution name is invalid.');
            }
            $systemFlag = $state->pairingSystem === 'fide-burstein-2026' ? '--burstein' : '--dutch';
            $command = PHP_OS_FAMILY === 'Windows'
                ? '"'.getenv('SystemRoot').'\\System32\\wsl.exe" -d '.$this->wslDistribution
                    .' -- '.escapeshellarg($this->toWslPath($this->executable))
                    .' '.$systemFlag.' '.escapeshellarg($this->toWslPath($input))
                    .' -p '.escapeshellarg($this->toWslPath($output))
                : [$this->executable, $systemFlag, $input, '-p', $output];
            $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $pipes = [];
            $process = proc_open($command, $descriptorSpec, $pipes, $directory);
            if (!is_resource($process)) {
                return $this->infrastructure('Could not start BBP Pairings.');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = '';
            $stderr = '';
            $started = hrtime(true);
            $timedOut = false;
            $reportedExitCode = -1;
            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $reportedExitCode = $status['exitcode'];
                    break;
                }
                if ((hrtime(true) - $started) / 1_000_000 > $this->timeoutMilliseconds) {
                    $timedOut = true;
                    proc_terminate($process, 9);
                    break;
                }
                usleep(10_000);
            } while (true);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closedExitCode = proc_close($process);
            $exitCode = $reportedExitCode >= 0 ? $reportedExitCode : $closedExitCode;
            if ($timedOut) {
                return new OracleResult(OracleStatus::Timeout, [], null, $stdout, $stderr, $exitCode, $this->metadata());
            }
            if ($exitCode !== 0) {
                $status = match ($exitCode) {
                    1 => OracleStatus::NoPairing,
                    3 => OracleStatus::InvalidInput,
                    4 => OracleStatus::ResourceLimit,
                    default => OracleStatus::InfrastructureError,
                };

                return new OracleResult($status, [], null, $stdout, $stderr, $exitCode, $this->metadata());
            }
            $contents = is_file($output) ? file_get_contents($output) : false;
            if (!is_string($contents)) {
                return $this->infrastructure('BBP did not create a pairing output.', $stdout, $stderr, $exitCode);
            }

            return $this->decodePairs($state, $contents, $stdout, $stderr, $exitCode);
        } finally {
            foreach ([$input, $output] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function decodePairs(TournamentState $state, string $contents, string $stdout, string $stderr, int $exitCode): OracleResult
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($contents));
        if ($lines === false || $lines === [] || preg_match('/^\d+$/D', $lines[0]) !== 1) {
            return $this->infrastructure('BBP pairing output is malformed.', $stdout, $stderr, $exitCode);
        }
        $expected = (int) array_shift($lines);
        if (count($lines) !== $expected) {
            return $this->infrastructure('BBP pairing count does not match its output.', $stdout, $stderr, $exitCode);
        }
        $idByTpn = [];
        foreach ($state->players as $player) {
            $idByTpn[$player->pairingNumber] = $player->id;
        }
        $pairs = [];
        $bye = null;
        foreach ($lines as $line) {
            if (preg_match('/^(\d+)\s+(\d+)$/D', trim($line), $matches) !== 1) {
                return $this->infrastructure('BBP pairing line is malformed.', $stdout, $stderr, $exitCode);
            }
            $whiteTpn = (int) $matches[1];
            $blackTpn = (int) $matches[2];
            if (!isset($idByTpn[$whiteTpn]) || ($blackTpn !== 0 && !isset($idByTpn[$blackTpn]))) {
                return $this->infrastructure('BBP referenced an unknown TPN.', $stdout, $stderr, $exitCode);
            }
            if ($blackTpn === 0) {
                $bye = $idByTpn[$whiteTpn];
            } else {
                $pairs[] = ['whitePlayerId' => $idByTpn[$whiteTpn], 'blackPlayerId' => $idByTpn[$blackTpn]];
            }
        }

        return new OracleResult(OracleStatus::Valid, $pairs, $bye, $stdout, $stderr, $exitCode, $this->metadata());
    }

    private function toWslPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('/^([A-Za-z]):\/(.*)$/D', $normalized, $matches) !== 1) {
            throw new \InvalidArgumentException('A Windows absolute path is required for WSL conversion.');
        }

        return '/mnt/'.strtolower($matches[1]).'/'.$matches[2];
    }

    /** @return array{upstreamCommit: string, upstream: string, executableSha256: string} */
    private function metadata(): array
    {
        return [
            'upstreamCommit' => '8f9e3c5ffdc4d7a08a33d31c6fb49b4e7acef4d5',
            'upstream' => 'https://github.com/BieremaBoyzProgramming/bbpPairings',
            'executableSha256' => '1dff7412b71cb1e2acba99c483b30cae9dfe1a3ac935697c9820970daa7a4421',
        ];
    }

    private function infrastructure(string $message, string $stdout = '', string $stderr = '', int $exitCode = -1): OracleResult
    {
        return new OracleResult(OracleStatus::InfrastructureError, [], null, $stdout, trim($stderr.' '.$message), $exitCode, $this->metadata());
    }
}
