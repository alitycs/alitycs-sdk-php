<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\AnalyticsEvent;
use Alitycs\BatchManager;
use Alitycs\BatchPayload;
use Alitycs\Config;
use Alitycs\EventContext;
use PHPUnit\Framework\TestCase;

final class BatchManagerTest extends TestCase
{
    private float $now = 1_000_000.0;

    /** @var list<BatchPayload> */
    private array $sent = [];

    // ---------------------------------------------------------------- size trigger

    public function testEventsBelowFlushSizeStayQueued(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 3]));

        $manager->add($this->event());
        $manager->add($this->event());

        $this->assertSame(2, $manager->pending());
        $this->assertSame([], $this->sent);
    }

    public function testReachingFlushSizeSendsImmediately(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 2, 'flushInterval' => 0]));

        $manager->add($this->event('first'));
        $manager->add($this->event('second'));

        $this->assertSame(0, $manager->pending());
        $this->assertCount(1, $this->sent);
        $this->assertSame(['first', 'second'], $this->eventNames($this->sent[0]));
    }

    public function testExplicitFlushDrainsOneBatch(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 25, 'flushInterval' => 0]));

        $manager->add($this->event('a'));
        $manager->add($this->event('b'));
        $manager->flush();

        $this->assertSame(0, $manager->pending());
        $this->assertCount(1, $this->sent);
        $this->assertSame(['a', 'b'], $this->eventNames($this->sent[0]));
        $this->assertSame('batch_', substr($this->sent[0]->batchId, 0, 6));
        $this->assertIsInt($this->sent[0]->sentAt);
    }

    public function testFlushOnAnEmptyQueueSendsNothing(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 25, 'flushInterval' => 0]));

        $manager->flush();

        $this->assertSame([], $this->sent);
    }

    // ------------------------------------------------------------------- queue cap

    public function testFullQueueDropsNewArrivals(): void
    {
        $manager = $this->makeManager(new Config('k', [
            'flushSize' => 25,
            'flushInterval' => 0,
            'maxQueueSize' => 2,
            'debug' => true,
        ]));

        $manager->add($this->event('kept-1'));
        $manager->add($this->event('kept-2'));
        $manager->add($this->event('dropped'));

        $this->assertSame(2, $manager->pending());
        $manager->flush();
        $this->assertSame(['kept-1', 'kept-2'], $this->eventNames($this->sent[0]));
    }

    // -------------------------------------------------- opportunistic interval check

    public function testElapsedIntervalFlushesOnTheNextAdd(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 25, 'flushInterval' => 5]));

        $manager->add($this->event('waiting'));
        $this->now += 5.0; // the interval elapses with nothing sending it
        $manager->add($this->event('trigger'));

        $this->assertSame(0, $manager->pending());
        $this->assertCount(1, $this->sent);
        $this->assertSame(['waiting', 'trigger'], $this->eventNames($this->sent[0]));
    }

    public function testUnelapsedIntervalDoesNotFlush(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 25, 'flushInterval' => 5]));

        $manager->add($this->event('a'));
        $this->now += 4.9;
        $manager->add($this->event('b'));

        $this->assertSame(2, $manager->pending());
        $this->assertSame([], $this->sent);
    }

    public function testZeroIntervalDisablesTheCheck(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 25, 'flushInterval' => 0]));

        $this->now += 999.0;
        $manager->add($this->event('a'));

        $this->assertSame(1, $manager->pending());
        $this->assertSame([], $this->sent);
    }

    public function testAFlushAttemptResetsTheIntervalClock(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 25, 'flushInterval' => 5]));

        $manager->add($this->event('a'));
        $this->now += 5.0;
        $manager->add($this->event('b')); // interval flush: both events go out
        $manager->add($this->event('c')); // clock was just reset — this one must stay queued

        $this->assertSame(1, $manager->pending());
        $this->assertSame(['a', 'b'], $this->eventNames($this->sent[0]));
    }

    // ------------------------------------------------------------- failure handling

    public function testSendFailuresAreSwallowedAndLogged(): void
    {
        $throwing = static function (): bool {
            throw new \RuntimeException('endpoint unreachable');
        };
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 1, 'flushInterval' => 0, 'debug' => true]),
            $throwing,
            fn (): float => $this->now
        );

        $manager->add($this->event('lost'));

        $this->assertSame(0, $manager->pending(), 'the batch is dropped after delivery fails');
    }

    // ---------------------------------------------------------------------- helpers

    private function makeManager(Config $config): BatchManager
    {
        return new BatchManager(
            $config,
            function (BatchPayload $payload): bool {
                $this->sent[] = $payload;

                return true;
            },
            fn (): float => $this->now
        );
    }

    private function event(string $name = 'test_event'): AnalyticsEvent
    {
        return new AnalyticsEvent(
            eventId: 'evt_' . $name,
            event: $name,
            eventType: \Alitycs\EventType::Track,
            anonymousId: 'anon_test',
            sessionId: 'sess_test',
            timestamp: 1234,
            properties: [],
            context: new EventContext('test', 'php'),
        );
    }

    /** @return list<string> */
    private function eventNames(BatchPayload $payload): array
    {
        return array_map(static fn (AnalyticsEvent $event) => $event->event, $payload->events);
    }
}
