<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsMatchTheOtherServerSdks(): void
    {
        $config = new Config('pk_test');

        $this->assertSame(Config::DEFAULT_ENDPOINT, $config->endpoint);
        $this->assertSame(25, $config->flushSize);
        $this->assertSame(10.0, $config->flushInterval);
        $this->assertSame(1000, $config->maxQueueSize);
        $this->assertSame(3, $config->maxRetries);
        $this->assertSame(10_000, $config->timeoutMs);
        $this->assertSame(30 * 60 * 1000, $config->sessionTimeout);
        $this->assertFalse($config->debug);
        $this->assertTrue($config->batching);
        $this->assertSame('pk_test', $config->apiKey());
    }

    public function testEveryOptionIsHonoured(): void
    {
        $config = new Config('pk_test', [
            'endpoint' => 'http://127.0.0.1:9999/events',
            'flushSize' => 2,
            'flushInterval' => 0,
            'maxQueueSize' => 5,
            'maxRetries' => 0,
            'timeoutMs' => 500,
            'sessionTimeout' => 60_000,
            'debug' => true,
            'batching' => false,
        ]);

        $this->assertSame('http://127.0.0.1:9999/events', $config->endpoint);
        $this->assertSame(2, $config->flushSize);
        $this->assertSame(0.0, $config->flushInterval);
        $this->assertSame(5, $config->maxQueueSize);
        $this->assertSame(0, $config->maxRetries);
        $this->assertSame(500, $config->timeoutMs);
        $this->assertSame(60_000, $config->sessionTimeout);
        $this->assertTrue($config->debug);
        $this->assertFalse($config->batching);
    }

    public static function invalidConfigProvider(): \Generator
    {
        yield 'blank api key' => ['apiKey' => '', 'options' => []];
        yield 'whitespace api key' => ['apiKey' => '   ', 'options' => []];
        yield 'unknown option (typo)' => ['apiKey' => 'k', 'options' => ['flushsize' => 2]];
        yield 'endpoint not a string' => ['apiKey' => 'k', 'options' => ['endpoint' => 42]];
        yield 'endpoint empty' => ['apiKey' => 'k', 'options' => ['endpoint' => '  ']];
        yield 'flushSize zero' => ['apiKey' => 'k', 'options' => ['flushSize' => 0]];
        yield 'flushSize not an int' => ['apiKey' => 'k', 'options' => ['flushSize' => '2']];
        yield 'flushInterval negative' => ['apiKey' => 'k', 'options' => ['flushInterval' => -1]];
        yield 'flushInterval NaN' => ['apiKey' => 'k', 'options' => ['flushInterval' => NAN]];
        yield 'flushInterval a string' => ['apiKey' => 'k', 'options' => ['flushInterval' => 'soon']];
        yield 'maxQueueSize zero' => ['apiKey' => 'k', 'options' => ['maxQueueSize' => 0]];
        yield 'maxQueueSize below flushSize' => ['apiKey' => 'k', 'options' => ['flushSize' => 10, 'maxQueueSize' => 5]];
        yield 'maxQueueSize one below default flushSize' => ['apiKey' => 'k', 'options' => ['maxQueueSize' => 24]];
        yield 'maxRetries negative' => ['apiKey' => 'k', 'options' => ['maxRetries' => -1]];
        yield 'timeoutMs zero' => ['apiKey' => 'k', 'options' => ['timeoutMs' => 0]];
        yield 'sessionTimeout zero' => ['apiKey' => 'k', 'options' => ['sessionTimeout' => 0]];
        yield 'debug not a bool' => ['apiKey' => 'k', 'options' => ['debug' => 1]];
        yield 'batching not a bool' => ['apiKey' => 'k', 'options' => ['batching' => 'yes']];
    }

    #[DataProvider('invalidConfigProvider')]
    public function testInvalidOptionsThrow(string $apiKey, array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config($apiKey, $options);
    }

    public function testQueueCapBelowFlushSizeNamesTheCombinationInTheError(): void
    {
        try {
            new Config('k', ['flushSize' => 10, 'maxQueueSize' => 5]);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('maxQueueSize', $error->getMessage());
            $this->assertStringContainsString('flushSize', $error->getMessage());
        }
    }

    public function testQueueCapExactlyAtFlushSizeIsAllowed(): void
    {
        // The last accepted event is the one that triggers the send, so equality works.
        $config = new Config('k', ['flushSize' => 5, 'maxQueueSize' => 5]);

        $this->assertSame(5, $config->maxQueueSize);
        $this->assertSame(5, $config->flushSize);
    }
}
