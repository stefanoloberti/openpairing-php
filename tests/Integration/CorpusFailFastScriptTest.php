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
}
