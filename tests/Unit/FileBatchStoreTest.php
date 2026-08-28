<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\FileBatchStore;
use PHPUnit\Framework\TestCase;

final class FileBatchStoreTest extends TestCase
{
    public function testLifecyclePersistsReloadsPausesAndAcknowledges(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $store = new FileBatchStore($path);
            self::assertTrue($store->enabled());
            $store->put('batch_exact', '{"batchId":"batch_exact"}', 2);
            self::assertSame(2, $store->pendingEvents());
            self::assertFileExists($path);

            $store->pause('batch_exact', 123456);
            $restarted = new FileBatchStore($path);
            self::assertSame(123456, $restarted->snapshot()[0]['pausedUntilMs']);
            self::assertSame('{"batchId":"batch_exact"}', $restarted->snapshot()[0]['body']);

            $restarted->acknowledge('batch_exact');
            self::assertSame(0, $restarted->pendingEvents());
            self::assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function testDisabledStoreIsANoop(): void
    {
        $store = new FileBatchStore(null);
        self::assertFalse($store->enabled());
        $store->put('batch', '{}', 1);
        $store->pause('batch', 1);
        $store->acknowledge('batch');
        self::assertSame([], $store->snapshot());
        self::assertSame(0, $store->pendingEvents());
    }

    public function testCorruptStateFailsInitialization(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'alitycs-php-wal-');
        file_put_contents($path, 'not-json');
        try {
            $this->expectException(\RuntimeException::class);
            new FileBatchStore($path);
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidRecordFailsInitialization(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'alitycs-php-wal-');
        file_put_contents($path, '{"version":1,"batches":[{"batchId":42}]}');
        try {
            $this->expectException(\RuntimeException::class);
            new FileBatchStore($path);
        } finally {
            @unlink($path);
        }
    }
}
