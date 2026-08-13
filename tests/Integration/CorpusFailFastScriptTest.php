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
        self::assertStringContainsString('mv -f -- "$temporary_stop" "$stop_file"', $script);
        self::assertStringContainsString('mv -f -- "$temporary_output" "$output"', $script);
        self::assertStringNotContainsString('--allow-empty', $script);
    }

    public function testCampaignChecksStopBeforeEveryShardAndAcceptsOnlyClassifiedReports(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/tools/install_large_field_campaign.sh');

        self::assertIsString($script);
        self::assertStringContainsString('test ! -e \\"\\$stop_file\\" || return 30', $script);
        self::assertStringContainsString('classify_shard_fail_fast.sh', $script);
        self::assertStringContainsString('quarantine/\\$label.json', $script);
        self::assertStringContainsString('mv -f -- \\"\\$temporary_report\\" \\"\\$report\\"', $script);
        self::assertStringContainsString('xargs -r -P', $script);
    }
}
