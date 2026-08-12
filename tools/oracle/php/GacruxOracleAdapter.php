<?php

declare(strict_types=1);

namespace OpenPairing\Development\Oracle;

use JsonException;
use OpenPairing\Domain\Tournament\TournamentState;
use OpenPairing\Format\Json\CanonicalJson;

final readonly class GacruxOracleAdapter
{
    public function __construct(
        private string $pythonExecutable,
        private string $upstreamDirectory,
        private string $bridgeScript,
        private int $timeoutMilliseconds = 10_000,
    ) {
    }

    public function pair(TournamentState $state): OracleResult
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'openpairing-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            return $this->infrastructure('Could not create an isolated temporary directory.');
        }

        $input = $directory.DIRECTORY_SEPARATOR.'input.json';
        try {
            $bytes = file_put_contents($input, (new CanonicalJson())->encode($state));
            if ($bytes === false) {
                return $this->infrastructure('Could not write the oracle input.');
            }

            $command = [
                $this->pythonExecutable,
                $this->bridgeScript,
                '--upstream',
                $this->upstreamDirectory,
                '--input',
                $input,
            ];
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $pipes = [];
            $process = proc_open($command, $descriptorSpec, $pipes, $directory, ['PYTHONHASHSEED' => '0']);
            if (!is_resource($process)) {
                return $this->infrastructure('Could not start the Gacrux oracle process.');
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $stdout = '';
            $stderr = '';
            $started = hrtime(true);
            $timedOut = false;

            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
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
            $exitCode = proc_close($process);

            if ($timedOut) {
                return new OracleResult(OracleStatus::Timeout, [], null, $stdout, $stderr, $exitCode, $this->metadata());
            }

            return $this->decodeResult($stdout, $stderr, $exitCode);
        } finally {
            if (is_file($input)) {
                unlink($input);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function decodeResult(string $stdout, string $stderr, int $exitCode): OracleResult
    {
        try {
            $data = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return new OracleResult(
                OracleStatus::InfrastructureError,
                [],
                null,
                $stdout,
                $stderr.' Invalid bridge JSON: '.$exception->getMessage(),
                $exitCode,
                $this->metadata(),
            );
        }

        if (!is_array($data) || !isset($data['status']) || !is_string($data['status'])) {
            return $this->infrastructure('Bridge returned an invalid result envelope.', $stdout, $stderr, $exitCode);
        }

        $status = OracleStatus::tryFrom($data['status']) ?? OracleStatus::InfrastructureError;
        $pairs = [];
        if (isset($data['pairs']) && is_array($data['pairs'])) {
            foreach ($data['pairs'] as $pair) {
                if (is_array($pair) && isset($pair['whitePlayerId'], $pair['blackPlayerId']) && is_int($pair['whitePlayerId']) && is_int($pair['blackPlayerId'])) {
                    $pairs[] = ['whitePlayerId' => $pair['whitePlayerId'], 'blackPlayerId' => $pair['blackPlayerId']];
                }
            }
        }
        $bye = isset($data['byePlayerId']) && is_int($data['byePlayerId']) ? $data['byePlayerId'] : null;

        return new OracleResult($status, $pairs, $bye, $stdout, $stderr, $exitCode, $this->metadata());
    }

    /** @return array{upstreamCommit: string, upstream: string} */
    private function metadata(): array
    {
        return [
            'upstreamCommit' => '14a34a2c2f36509b110e4f25d6247f31fc4bf2f5',
            'upstream' => 'https://github.com/OttoMilvang/TieBreakServer',
        ];
    }

    private function infrastructure(string $message, string $stdout = '', string $stderr = '', int $exitCode = -1): OracleResult
    {
        return new OracleResult(OracleStatus::InfrastructureError, [], null, $stdout, trim($stderr.' '.$message), $exitCode, $this->metadata());
    }
}

