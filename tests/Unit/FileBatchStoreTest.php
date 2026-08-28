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
            self::assertTrue($store->contains('batch_exact'));
            self::assertSame(2, $store->pendingEvents());
            self::assertFileExists($path);

            $store->pause('batch_exact', 123456);
            $restarted = new FileBatchStore($path);
            self::assertSame(123456, $restarted->snapshot()[0]['pausedUntilMs']);
            self::assertSame('{"batchId":"batch_exact"}', $restarted->snapshot()[0]['body']);

            $restarted->acknowledge('batch_exact');
            self::assertFalse($restarted->contains('batch_exact'));
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

    public function testForkResetDisablesChildWithoutRemovingParentFile(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $store = new FileBatchStore($path);
            $store->put('batch_parent', '{}', 1);

            self::assertTrue($store->resetForChild());
            self::assertFalse($store->enabled());
            self::assertSame(0, $store->pendingEvents());
            self::assertFileExists($path, 'the child must not remove the parent-owned WAL');
        } finally {
            @unlink($path);
        }
    }

    public function testConfiguredPendingEventLimitBoundsWalGrowth(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $store = new FileBatchStore($path, 2);
            $store->put('batch_first', '{}', 2);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('event limit');
            $store->put('batch_overflow', '{}', 1);
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidAndOversizedPersistenceLimitsFailInitialization(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileBatchStore(null, 0);
    }

    public function testPersistedStateAboveLimitFailsInitialization(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'alitycs-php-wal-');
        file_put_contents(
            $path,
            '{"version":1,"batches":[{"batchId":"batch","body":"{}","eventCount":2,"pausedUntilMs":null}]}'
        );
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('configured event limit');
            new FileBatchStore($path, 1);
        } finally {
            @unlink($path);
        }
    }

    public function testFailedPutRollsBackMemory(): void
    {
        $parentFile = (string) tempnam(sys_get_temp_dir(), 'alitycs-php-parent-file-');
        try {
            $store = new FileBatchStore($parentFile . '/wal.json');
            try {
                $store->put('batch', '{}', 1);
                $this->fail('put unexpectedly succeeded');
            } catch (\RuntimeException) {
                self::assertSame(0, $store->pendingEvents());
                self::assertSame([], $store->snapshot());
            }
        } finally {
            @unlink($parentFile);
        }
    }

    public function testFailedAcknowledgeAndPauseRollBackMemory(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        $blockingDirectory = sys_get_temp_dir() . '/alitycs-php-block-' . bin2hex(random_bytes(6));
        mkdir($blockingDirectory, 0700);
        file_put_contents($blockingDirectory . '/marker', 'keep non-empty');
        try {
            $store = new FileBatchStore($path);
            $store->put('batch', '{}', 1);
            $store->put('batch_other', '{}', 1);
            (new \ReflectionProperty(FileBatchStore::class, 'path'))->setValue($store, $blockingDirectory);

            try {
                $store->pause('batch', 123);
                $this->fail('pause unexpectedly succeeded');
            } catch (\RuntimeException) {
                self::assertNull($store->snapshot()[0]['pausedUntilMs']);
            }
            try {
                $store->acknowledge('batch');
                $this->fail('acknowledge unexpectedly succeeded');
            } catch (\RuntimeException) {
                self::assertSame(2, $store->pendingEvents());
            }
        } finally {
            @unlink($path);
            @unlink($blockingDirectory . '/marker');
            @rmdir($blockingDirectory);
        }
    }

    public function testDirectorySyncBestEffortIgnoresUnsupportedPath(): void
    {
        $store = new FileBatchStore(null);
        $method = new \ReflectionMethod(FileBatchStore::class, 'syncDirectoryBestEffort');
        $method->invoke($store, sys_get_temp_dir() . '/alitycs-definitely-missing-' . bin2hex(random_bytes(6)));

        self::assertFalse($store->enabled());
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

    public function testDuplicateBatchIdsFailInitialization(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'alitycs-php-wal-');
        file_put_contents(
            $path,
            '{"version":1,"batches":['
            . '{"batchId":"duplicate","body":"{}","eventCount":1,"pausedUntilMs":null},'
            . '{"batchId":"duplicate","body":"{}","eventCount":1,"pausedUntilMs":null}'
            . ']}'
        );
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Duplicate Alitycs persistence record');
            new FileBatchStore($path);
        } finally {
            @unlink($path);
        }
    }
}
