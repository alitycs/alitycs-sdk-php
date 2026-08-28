<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\Client;
use Alitycs\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Process-level lifecycle: the single shared shutdown registration instead of one hook
 * per instance, deregistration on shutdown/destruction, and the child-process reset
 * that keeps a forked worker from double-sending the queue it inherited.
 */
final class ClientLifecycleTest extends TestCase
{
    /** @var list<Client> */
    private array $clients = [];

    protected function tearDown(): void
    {
        // Shut every client down so the process-wide shutdown hook has nothing to do
        // when phpunit itself exits.
        foreach ($this->clients as $client) {
            $client->shutdown();
        }
        $this->clients = [];
    }

    public function testLiveClientRegistryTracksCreationAndExplicitShutdown(): void
    {
        $first = $this->makeClient();
        $second = $this->makeClient();

        $this->assertSame(2, self::liveCount());

        $first->shutdown();
        $this->assertSame(1, self::liveCount(), 'an explicitly shut-down client leaves the registry');

        $second->shutdown();
        $this->assertSame(0, self::liveCount());
    }

    public function testDestructionRemovesTheClientFromTheRegistry(): void
    {
        $client = $this->makeClient();
        $this->assertSame(1, self::liveCount());

        // Drop both references — the local one and tearDown's — before asserting.
        array_pop($this->clients);
        unset($client);
        $this->assertSame(0, self::liveCount(), 'a destroyed client must not linger in the registry');
    }

    public function testShutDownClientAcceptsNoFurtherEvents(): void
    {
        $client = $this->makeClient();
        $client->shutdown();

        $this->assertSame(0, self::liveCount());
        $client->track('after_shutdown');

        $this->assertSame(0, $client->pending(), 'a shut-down client must stay inert');
        $this->assertTrue(self::isClosed($client));
    }

    public function testResetForChildProcessDropsTheInheritedQueue(): void
    {
        $client = $this->makeClient();
        $client->track('belongs_to_parent');

        $this->assertSame(1, $client->pending());

        Client::resetForChildProcess();

        $this->assertSame(
            0,
            $client->pending(),
            'the child must not deliver events whose delivery the parent still owns'
        );
        $client->shutdown();
    }

    public function testPidMismatchLazilyAdoptsTheCurrentProcess(): void
    {
        $client = $this->makeClient();
        $client->track('belongs_to_parent');
        $creatorPid = new \ReflectionProperty(Client::class, 'creatorPid');
        $creatorPid->setValue(null, (getmypid() ?: 1) + 1);

        $this->assertSame(0, $client->pending());
    }

    public function testResetForChildProcessKeepsGlobalPropertiesAndRotatesTheSessionId(): void
    {
        $client = $this->makeClient();
        $client->setGlobalProperties(['scenario' => 'fork']);
        $client->track('queued');
        $sessionIdBefore = self::sessionIdOf($client);

        Client::resetForChildProcess();

        $this->assertNotSame(
            $sessionIdBefore,
            self::sessionIdOf($client),
            'the child stops appending activity to the parent session'
        );
        $this->assertSame(['scenario' => 'fork'], $client->getGlobalProperties());
        $client->shutdown();
    }

    public function testEmptyLiveClientRegistryCanBeSnapshottedBeforeFirstClient(): void
    {
        $registry = new \ReflectionProperty(Client::class, 'liveClients');
        $registry->setValue(null, null);

        $snapshot = new \ReflectionMethod(Client::class, 'snapshotLiveClients');

        $this->assertSame([], $snapshot->invoke(null));
    }

    public function testProcessShutdownHandlerDrainsAndDeregistersLiveClients(): void
    {
        $client = $this->makeClient();
        $flushAll = new \ReflectionMethod(Client::class, 'flushAllAtShutdown');

        $flushAll->invoke(null);

        $this->assertTrue(self::isClosed($client));
        $this->assertSame(0, self::liveCount());
    }

    public function testUnbatchedDeliveryFailureDoesNotQueueTheEvent(): void
    {
        $client = new Client('pk_lifecycle_test', [
            'endpoint' => 'http://127.0.0.1:9/events',
            'batching' => false,
            'maxRetries' => 0,
            'timeoutMs' => 100,
        ]);
        $this->clients[] = $client;

        $client->track('unreachable_delivery');

        $this->assertSame(0, $client->pending());
    }

    // ---------------------------------------------------------------------- helpers

    private function makeClient(): Client
    {
        // No endpoint traffic can happen here: nothing is sent until flush/shutdown and
        // every test drains its clients.
        $client = new Client('pk_lifecycle_test', ['endpoint' => 'http://127.0.0.1:9/events', 'flushInterval' => 0]);
        $this->clients[] = $client;

        return $client;
    }

    private static function liveCount(): int
    {
        return count(self::registry() ?? new \WeakMap());
    }

    /** @return \WeakMap<Client, true>|null */
    private static function registry(): ?\WeakMap
    {
        $property = new \ReflectionProperty(Client::class, 'liveClients');

        return $property->getValue(null);
    }

    private static function isClosed(Client $client): bool
    {
        $closed = new \ReflectionProperty(Client::class, 'closed');

        return $closed->getValue($client);
    }

    private static function sessionIdOf(Client $client): string
    {
        $manager = (new \ReflectionProperty(Client::class, 'sessionManager'))->getValue($client);
        assert($manager instanceof SessionManager);
        $session = (new \ReflectionProperty(SessionManager::class, 'session'))->getValue($manager);

        return $session->id;
    }
}
