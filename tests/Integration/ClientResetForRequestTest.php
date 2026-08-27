<?php

declare(strict_types=1);

namespace Alitycs\Tests\Integration;

use Alitycs\Client;
use PHPUnit\Framework\TestCase;

/**
 * Proves that {@see Client::resetForRequest()} removes every per-request identity from
 * a long-lived client instance. Under Laravel Octane / Swoole / RoadRunner one client
 * instance serves many logical requests; without the reset, the previous request's
 * identified user and global properties leak into the next request's events.
 */
final class ClientResetForRequestTest extends TestCase
{
    private ?LocalIngestServer $server = null;

    private ?Client $client = null;

    protected function tearDown(): void
    {
        // Close in-test so the terminal shutdown callback is a cheap no-op instead of
        // a delivery attempt against a stopped listener.
        $this->client?->shutdown();
        $this->client = null;

        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
    }

    public function testSequentialRequestsOnOneInstanceDoNotLeakIdentity(): void
    {
        $this->server = LocalIngestServer::start();
        $this->client = new Client('pk_client_test', [
            'endpoint' => $this->server->endpoint(),
            'flushSize' => 10,
        ]);

        // --- logical request A ---
        $this->client->setGlobalProperties(['tenant' => 'tenant_a', 'region' => 'eu']);
        $this->client->identify('usr_request_a', ['plan' => 'pro']);
        $this->client->track('checkout_completed', ['n' => 1]);
        $this->client->flush();

        // --- logical request B on the SAME instance, after the per-request reset ---
        $this->client->resetForRequest();
        $this->assertSame([], $this->client->getGlobalProperties(), 'reset clears global properties');

        $this->client->identify('usr_request_b');
        $this->client->track('checkout_completed', ['n' => 2]);
        $this->client->flush();

        $requests = $this->server->requests();
        self::assertCount(2, $requests);

        $eventsA = $requests[0]['body']['events'];
        $this->assertCount(2, $eventsA);
        foreach ($eventsA as $event) {
            $this->assertSame('usr_request_a', $event['userId']);
            $this->assertSame('tenant_a', $event['properties']['tenant']);
        }

        $eventsB = $requests[1]['body']['events'];
        $this->assertCount(2, $eventsB);
        foreach ($eventsB as $event) {
            // The heart of the fix: request B carries no trace of request A's identity.
            $this->assertSame('usr_request_b', $event['userId'], 'stale userId leaked across requests');
            $this->assertArrayNotHasKey('tenant', $event['properties'], 'stale global property leaked across requests');
            $this->assertArrayNotHasKey('region', $event['properties'], 'stale global property leaked across requests');
            $this->assertNotSame($eventsA[0]['sessionId'], $event['sessionId'], 'session id must rotate per request');
            $this->assertNotSame($eventsA[0]['anonymousId'], $event['anonymousId'], 'anonymous id must rotate per request');
        }
        $this->assertSame(
            'usr_request_b',
            $eventsB[0]['properties']['userId'] ?? null,
            'the fresh identify event names the new user'
        );
    }

    public function testEventsQueuedBeforeTheResetAreNotDropped(): void
    {
        $this->server = LocalIngestServer::start();
        $this->client = new Client('pk_client_test', [
            'endpoint' => $this->server->endpoint(),
            'flushSize' => 10,
        ]);

        $this->client->identify('usr_request_a');
        $this->client->track('still_attributed', []);
        $this->client->resetForRequest();
        $this->client->flush();

        $events = $this->server->requests()[0]['body']['events'];
        // The identify and the track were already queued — the reset must not drop
        // them, only stop them from being joined by request B's identity.
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('usr_request_a', $event['userId'], 'the reset never drops queued events');
        }
    }
}
