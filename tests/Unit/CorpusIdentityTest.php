<?php

declare(strict_types=1);

namespace OpenPairing\Tests\Unit;

use OpenPairing\Diagnostics\CorpusIdentity;
use PHPUnit\Framework\TestCase;

final class CorpusIdentityTest extends TestCase
{
    public function testIdentityUsesAStableRelativePathOrderAndExactLimit(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'openpairing-corpus-'.bin2hex(random_bytes(8));
        mkdir($root.DIRECTORY_SEPARATOR.'z', 0777, true);
        mkdir($root.DIRECTORY_SEPARATOR.'a', 0777, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'z'.DIRECTORY_SEPARATOR.'last.trf', 'last');
        file_put_contents($root.DIRECTORY_SEPARATOR.'a'.DIRECTORY_SEPARATOR.'first.trf', 'first');
        file_put_contents($root.DIRECTORY_SEPARATOR.'middle.trf', 'middle');
        file_put_contents($root.DIRECTORY_SEPARATOR.'ignored.txt', 'ignored');

        try {
            $first = CorpusIdentity::capture($root, 2);
            $second = CorpusIdentity::capture($root, 2);

            self::assertSame(['a/first.trf', 'middle.trf'], array_column($first['files'], 'relative'));
            self::assertSame(2, $first['identity']['fileCount']);
            self::assertSame($first['identity'], $second['identity']);
            self::assertSame(64, strlen($first['identity']['manifestSha256']));
        } finally {
            unlink($root.DIRECTORY_SEPARATOR.'z'.DIRECTORY_SEPARATOR.'last.trf');
            unlink($root.DIRECTORY_SEPARATOR.'a'.DIRECTORY_SEPARATOR.'first.trf');
            unlink($root.DIRECTORY_SEPARATOR.'middle.trf');
            unlink($root.DIRECTORY_SEPARATOR.'ignored.txt');
            rmdir($root.DIRECTORY_SEPARATOR.'z');
            rmdir($root.DIRECTORY_SEPARATOR.'a');
            rmdir($root);
        }
    }

    public function testAnyByteChangeChangesTheManifestHash(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'openpairing-corpus-'.bin2hex(random_bytes(8));
        mkdir($root);
        $path = $root.DIRECTORY_SEPARATOR.'one.trf';
        file_put_contents($path, 'one');
        try {
            $before = CorpusIdentity::capture($root, 0)['identity']['manifestSha256'];
            file_put_contents($path, 'two');
            $after = CorpusIdentity::capture($root, 0)['identity']['manifestSha256'];
            self::assertNotSame($before, $after);
        } finally {
            unlink($path);
            rmdir($root);
        }
    }

    public function testRejectsBytesThatDifferFromTheCapturedManifestEntry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corpus file changed during replay');
        CorpusIdentity::assertRawFileMatches([
            'absolute' => '/irrelevant/one.trf',
            'relative' => 'one.trf',
            'bytes' => 3,
            'sha256' => hash('sha256', 'one'),
        ], 'two');
    }
}
