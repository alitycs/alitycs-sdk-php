<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\AnalyticsEvent;
use Alitycs\BatchManager;
use Alitycs\BatchPayload;
use Alitycs\Config;
use Alitycs\DeliveryResult;
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

    public function testFillingTheQueueToItsCapStillTriggersDelivery(): void
    {
        // The cap can no longer sit below flushSize (Config rejects the combination:
        // below the threshold a send could never fire and everything would overflow).
        // At equality the event that fills the queue is exactly the one that sends.
        $manager = $this->makeManager(new Config('k', [
            'flushSize' => 3,
            'flushInterval' => 0,
            'maxQueueSize' => 3,
            'debug' => true,
        ]));

        $manager->add($this->event('kept-1'));
        $manager->add($this->event('kept-2'));

        $this->assertSame(2, $manager->pending());
        $this->assertSame([], $this->sent, 'below flushSize nothing is sent yet');

        $manager->add($this->event('kept-3'));
        $this->assertSame(0, $manager->pending(), 'the cap-filling add triggers the size flush');
        $this->assertSame(['kept-1', 'kept-2', 'kept-3'], $this->eventNames($this->sent[0]));
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
        $this->assertSame(1, $manager->lostTotal());
        $this->assertSame(0, $manager->deliveredTotal());
    }

    public function testRefusedBatchIsSplitAndBothHalvesRetried(): void
    {
        $manager = $this->makeManagerWithSend(new Config('k', ['flushSize' => 25, 'flushInterval' => 0]));

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $manager->add($this->event($name));
        }
        $manager->flush();

        // The whole batch of four is refused once; each half splits again because this
        // fake refuses any payload larger than one event. Every single lands in order.
        $sizes = array_map(static fn (BatchPayload $payload): int => count($payload->events), $this->sent);
        $this->assertSame([4, 2, 1, 1, 2, 1, 1], $sizes);
        $singles = [];
        foreach ($this->sent as $payload) {
            if (count($payload->events) === 1) {
                $singles[] = $payload->events[0]->event;
            }
        }
        $this->assertSame(['a', 'b', 'c', 'd'], $singles);
        $this->assertSame(4, $manager->deliveredTotal());
        $this->assertSame(0, $manager->lostTotal());
        $this->assertSame(0, $manager->pending());
    }

    public function testRefusedSingleEventIsDroppedAndCountedLost(): void
    {
        $refusing = static fn (): bool => false;
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 25, 'flushInterval' => 0]),
            $refusing,
            fn (): float => $this->now
        );

        $manager->add($this->event('poison'));
        $manager->flush();

        // Re-queueing a server-refused event would poison every future batch.
        $this->assertSame(0, $manager->pending());
        $this->assertSame(1, $manager->lostTotal());
    }

    public function testAuthenticationRejectionIsNotSplit(): void
    {
        $manager = $this->makeManagerWithSend(
            new Config('k', ['flushSize' => 25, 'flushInterval' => 0]),
            function (BatchPayload $payload): DeliveryResult {
                $this->sent[] = $payload;

                return DeliveryResult::rejected(401);
            },
        );
        foreach (range(1, 10) as $index) {
            $manager->add($this->event("auth-$index"));
        }
        $manager->flush();

        $this->assertCount(1, $this->sent, 'an auth failure must not amplify into per-event requests');
        $this->assertSame(10, $manager->lostTotal());
    }

    public function testBatchRejectionSplitIsBoundedToSixtyFourSends(): void
    {
        $manager = $this->makeManagerWithSend(
            new Config('k', ['flushSize' => 100, 'flushInterval' => 0, 'maxQueueSize' => 100]),
            function (BatchPayload $payload): DeliveryResult {
                $this->sent[] = $payload;

                return DeliveryResult::rejected(400);
            },
        );
        foreach (range(1, 100) as $index) {
            $manager->add($this->event("split-$index"));
        }

        $this->assertCount(64, $this->sent);
        $this->assertSame(100, $manager->lostTotal());
    }

    public function testRefusedDeliveriesAreCountedLostInsteadOfAccumulating(): void
    {
        // With maxQueueSize >= flushSize enforced, the queue cannot silently fill up:
        // each size-triggered send resolves its events as delivered or lost.
        $refusing = static fn (): bool => false;
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 2, 'flushInterval' => 0, 'maxQueueSize' => 2, 'debug' => true]),
            $refusing,
            fn (): float => $this->now
        );

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $manager->add($this->event($name));
        }

        $this->assertSame(0, $manager->pending(), 'nothing accumulates past a refused send');
        $this->assertSame(4, $manager->lostTotal());
        $this->assertSame(0, $manager->deliveredTotal());
    }

    public function testDurableTransientFailureLeavesOwnershipWithTheWal(): void
    {
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 25, 'flushInterval' => 0]),
            static fn (): DeliveryResult => DeliveryResult::transient(503),
            clock: fn (): float => $this->now,
            recover: static fn (): bool => true,
            durablePending: static fn (): int => 1,
            durable: true,
        );
        $manager->add($this->event('durable'));
        $manager->flush();

        $this->assertSame(1, $manager->pending());
        $this->assertSame(0, $manager->lostTotal());
    }

    public function testTransientFailureWithoutPersistenceIsCountedLost(): void
    {
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 25, 'flushInterval' => 0]),
            static fn (): DeliveryResult => DeliveryResult::transient(503),
            fn (): float => $this->now,
        );
        $manager->add($this->event('transient'));
        $manager->flush();

        $this->assertSame(0, $manager->pending());
        $this->assertSame(1, $manager->lostTotal());
    }

    public function testRecoveryExceptionLeavesMemoryQueueUntouched(): void
    {
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 25, 'flushInterval' => 0]),
            static fn (): bool => true,
            clock: fn (): float => $this->now,
            recover: static function (): bool {
                throw new \RuntimeException('bad WAL');
            },
        );
        $manager->add($this->event('waiting'));
        $manager->flush();

        $this->assertSame(1, $manager->pending());
    }

    public function testShutdownPersistsQueuedEventsInFifoOrderWhenRecoveryIsBlocked(): void
    {
        $durablePending = 1;
        $persisted = [];
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 2, 'flushInterval' => 0, 'maxQueueSize' => 5]),
            static fn (): DeliveryResult => DeliveryResult::accepted(202),
            clock: fn (): float => $this->now,
            recover: static fn (): bool => false,
            durablePending: static function () use (&$durablePending): int {
                return $durablePending;
            },
            durable: true,
            persist: function (BatchPayload $payload) use (&$persisted, &$durablePending): bool {
                $persisted[] = $payload;
                $durablePending += count($payload->events);

                return true;
            },
        );
        foreach (['a', 'b', 'c'] as $name) {
            $manager->add($this->event($name));
        }

        $manager->shutdown();

        $this->assertSame([['a'], ['b'], ['c']], array_map($this->eventNames(...), $persisted));
        $this->assertSame(4, $manager->pending());
        $this->assertSame(0, $manager->lostTotal());
    }

    public function testShutdownCountsQueuedEventsLostWhenFallbackPersistenceFails(): void
    {
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 2, 'flushInterval' => 0]),
            static fn (): DeliveryResult => DeliveryResult::accepted(202),
            clock: fn (): float => $this->now,
            recover: static fn (): bool => false,
            durablePending: static fn (): int => 1,
            durable: true,
            persist: static fn (): bool => false,
        );
        $manager->add($this->event('lost-at-shutdown'));

        $manager->shutdown();

        $this->assertSame(1, $manager->pending(), 'only the older durable record remains');
        $this->assertSame(1, $manager->lostTotal());
    }

    public function testShutdownPersistsWhatFitsBeforeCountingTheRemainderLost(): void
    {
        $durablePending = 1;
        $persisted = [];
        $manager = new BatchManager(
            new Config('k', ['flushSize' => 3, 'flushInterval' => 0]),
            static fn (): DeliveryResult => DeliveryResult::accepted(202),
            clock: fn (): float => $this->now,
            recover: static fn (): bool => false,
            durablePending: static function () use (&$durablePending): int {
                return $durablePending;
            },
            durable: true,
            persist: function (BatchPayload $payload) use (&$persisted, &$durablePending): bool {
                if ($persisted !== []) {
                    return false;
                }
                $persisted[] = $payload;
                $durablePending++;

                return true;
            },
        );
        foreach (['saved', 'lost-1', 'lost-2'] as $name) {
            $manager->add($this->event($name));
        }

        $manager->shutdown();

        $this->assertSame(['saved'], $this->eventNames($persisted[0]));
        $this->assertSame(2, $manager->pending());
        $this->assertSame(2, $manager->lostTotal());
    }

    // ------------------------------------------------------------------ fork safety

    public function testResetForChildHandsInheritedEventsBackToTheParent(): void
    {
        $manager = $this->makeManager(new Config('k', ['flushSize' => 25, 'flushInterval' => 0]));

        $manager->add($this->event('inherited'));
        $manager->resetForChild();

        $this->assertSame(0, $manager->pending(), 'the child must not deliver the parent queue');
        $this->assertSame([], $this->sent, 'nothing may be sent from the child');
        $this->assertSame(
            0,
            $manager->lostTotal(),
            'ownership moved back to the parent — nothing was lost'
        );
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

    /**
     * Manager whose send closure refuses any payload larger than one event, mirroring a
     * server that rejects whole batches while singles pass.
     */
    private function makeManagerWithSend(Config $config, ?\Closure $send = null): BatchManager
    {
        return new BatchManager(
            $config,
            $send ?? function (BatchPayload $payload): bool {
                $this->sent[] = $payload;

                return count($payload->events) <= 1;
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
