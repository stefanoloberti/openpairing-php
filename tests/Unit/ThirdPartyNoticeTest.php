<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ThirdPartyNoticeTest extends TestCase
{
    public function testEmbeddedGacruxAndBbpNoticesRemainDistributionReady(): void
    {
        $root = dirname(__DIR__, 2);
        $notice = file_get_contents($root.'/NOTICE');

        self::assertIsString($notice);
        self::assertStringContainsString(
            "Copyright (c) 2024 FIDE\n\nPermission is hereby granted",
            str_replace("\r\n", "\n", $notice),
        );
        self::assertStringContainsString('The incorporated', $notice);
        self::assertStringNotContainsString('When Gacrux-derived code is incorporated', $notice);
        self::assertStringContainsString('Copyright 2016-2026 Jeremy Bierema', $notice);
        self::assertStringContainsString('https://www.gacrux.no/', $notice);
    }
}
