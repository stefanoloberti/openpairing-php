<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class CorpusFailFastScriptTest extends TestCase
{
    public function testShardClassifierIsPersistentAtomicAndFailClosed(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/tools/corpus/classify_shard_fail_fast.sh');

        self::assertIsString($script);
        self::assertStringContainsString('set -euo pipefail', $script);
        self::assertStringContainsString('classifier-infrastructure-error', $script);
        self::assertStringContainsString('inferior-to-checker-optimum', $script);
        self::assertStringContainsString('largeField.candidateNoMatching', $script);
        self::assertStringContainsString('roundsEngineNoSolution', $script);
        self::assertStringContainsString('roundsEngineResourceLimit', $script);
        self::assertStringContainsString('zero-evaluated-cases', $script);
        self::assertStringContainsString('incomplete-evaluation', $script);
        self::assertStringContainsString('report-diagnostics-', $script);
        self::assertStringContainsString('"roundsAttempted"', $script);
        self::assertStringContainsString('mv -f -- "$temporary_stop" "$stop_file"', $script);
        self::assertStringContainsString('mv -f -- "$temporary_output" "$output"', $script);
        self::assertStringNotContainsString('--allow-empty', $script);
    }

    public function testCampaignChecksStopBeforeEveryShardAndAcceptsOnlyClassifiedReports(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/tools/install_large_field_campaign.sh');

        self::assertIsString($script);
        self::assertStringContainsString('test ! -e "\\$stop_file" || return 30', $script);
        self::assertStringContainsString('classify_shard_fail_fast.sh', $script);
        self::assertStringContainsString('quarantine/\\$label.json', $script);
        self::assertStringContainsString('mv -f -- "\\$temporary_report" "\\$report"', $script);
        self::assertStringContainsString('xargs -r -P "\$workers"', $script);
        self::assertStringNotContainsString('xargs -r -P \\"\$workers\\"', $script);
        self::assertStringContainsString('verifier-infrastructure-error', $script);
        self::assertStringContainsString('aggregate_shards.php', $script);
        self::assertStringContainsString('aggregate.json.tmp', $script);
        self::assertStringContainsString('shardGateSha256', $script);
        self::assertStringContainsString('campaignRunnerSha256', $script);
        self::assertStringContainsString('campaign_runner_sha256', $script);
        self::assertStringContainsString('realpath(\\$argv[2])', $script);
        self::assertStringContainsString('.classification.', $script);
        self::assertStringContainsString('EXPECTED_SOURCE_TREE_SHA256', $script);
        self::assertStringContainsString('expected_source_tree_sha256', $script);
        self::assertStringContainsString('expected_harness_sha256', $script);
        self::assertStringContainsString('expected_campaign_commit', $script);
        self::assertStringContainsString('campaign-identity.lock.json', $script);
        self::assertStringContainsString('runtime-identity.lock.json', $script);
        self::assertStringContainsString('assert_campaign_identity', $script);
        self::assertStringContainsString('OPENPAIRING_BIGINT_BACKEND=gmp', $script);
        self::assertStringContainsString('"arithmeticBackend"', $script);
        self::assertStringContainsString('report-identity-backend-mismatch', $script);
        self::assertStringContainsString('(\$report["productionOnly"] ?? false) !== true', $script);
    }

    public function testSupervisorDoesNotRestartAfterAConfirmedGateFailure(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/tools/install_corpus_supervisor.sh');

        self::assertIsString($script);
        self::assertStringContainsString('Restart=no', $script);
        self::assertStringNotContainsString('Restart=on-failure', $script);
        self::assertStringContainsString('systemctl enable', $script);
        self::assertStringContainsString('stages=("${@:3}")', $script);
        self::assertStringContainsString('Unsafe campaign stage', $script);
    }

    public function testCrossWorkerImporterValidatesBeforePrimarySemanticGateAndPromotesClassificationLast(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/tools/corpus/import_cross_worker_shard.sh');

        self::assertIsString($script);
        self::assertStringContainsString('set -Eeuo pipefail', $script);
        self::assertStringContainsString('flock -n 9', $script);
        self::assertStringContainsString('validate_cross_worker_import.php', $script);
        self::assertStringContainsString('OPENPAIRING_IMPORT_AUTOLOAD="$source_tree/vendor/autoload.php"', $script);
        self::assertStringContainsString('classify_shard_fail_fast.sh', $script);
        self::assertStringContainsString('integrity validation failed without stopping the primary campaign', $script);
        self::assertStringContainsString('Primary verifier is already computing', $script);
        self::assertStringContainsString('crossWorkerImport', $script);
        self::assertStringContainsString('importerSha256', $script);
        self::assertStringContainsString(
            '"$temporary_classification" "$final_report" "$temporary_final_attestation" "$temporary_final_validation"',
            $script,
        );
        self::assertStringNotContainsString(
            '"$temporary_classification" "$final_report" "$final_attestation" "$final_validation"',
            $script,
        );
        self::assertStringContainsString('mv -- "$temporary_report" "$final_report"', $script);
        self::assertStringContainsString('mv -- "$temporary_final_classification" "$final_classification"', $script);
        self::assertLessThan(
            strpos($script, 'mv -- "$temporary_final_classification" "$final_classification"'),
            strpos($script, 'mv -- "$temporary_report" "$final_report"'),
        );
    }

    public function testCrossWorkerTransportIsHashVerifiedAndInvokesOnlyThePrimaryImporter(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/tools/corpus/transfer_cross_worker_shard.ps1');

        self::assertIsString($script);
        self::assertStringContainsString("[ValidatePattern('^s(19[2-9]|2[0-4][0-9]|25[0-5])$')]", $script);
        self::assertStringContainsString("'python-69'", $script);
        self::assertStringContainsString("'doppia-3090'", $script);
        self::assertStringContainsString("'base64', '-w', '0'", $script);
        self::assertStringContainsString('[IO.Compression.GZipStream]::new(', $script);
        self::assertStringContainsString("gzip -dc >\"\$temporary_destination\"", $script);
        self::assertStringContainsString('sha256sum', $script);
        self::assertStringContainsString('temporary_destination="$destination.tmp.$$"', $script);
        self::assertStringContainsString('mv -- "$temporary_destination" "$destination"', $script);
        self::assertStringContainsString('$process.StandardOutput.ReadToEndAsync()', $script);
        self::assertStringContainsString('$process.StandardError.ReadToEndAsync()', $script);
        self::assertStringContainsString("\$payload = ([Console]::In.ReadToEnd() -replace '\\s', '')", $script);
        self::assertStringContainsString('$offset += 1024', $script);
        self::assertStringContainsString('$base64.Substring($offset, $length)', $script);
        self::assertStringContainsString('$process.StandardInput.Close()', $script);
        self::assertStringContainsString('& wsl.exe -d ChessPairings-Ubuntu-24.04 -- bash $linuxScriptTemporary', $script);
        self::assertStringContainsString('tooling/911bbab/corpus/import_cross_worker_shard.sh', $script);
        self::assertStringNotContainsString('scp ', $script);
    }
}
