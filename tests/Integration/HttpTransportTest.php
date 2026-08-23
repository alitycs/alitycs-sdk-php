<?php

declare(strict_types=1);

namespace Alitycs\Tests\Integration;

use Alitycs\AnalyticsEvent;
use Alitycs\BatchPayload;
use Alitycs\EventContext;
use Alitycs\EventType;
use Alitycs\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Exercises HttpTransport against the real `php -S` listener — genuine curl traffic,
 * with wall-clock backoff replaced by a recorder so retry timing stays deterministic.
 */
final class HttpTransportTest extends TestCase
{
    private ?LocalIngestServer $server = null;

    /** @var list<int> */
    private array $sleeps = [];

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
    }

    public function testAcceptedBatchReturnsTrueAndSendsTheWireHeaders(): void
    {
        $transport = $this->transport();

        $this->assertTrue($transport->send($this->payload()));

        $requests = $this->server->requests();
        self::assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('Bearer pk_transport_test', $requests[0]['authorization']);
        $this->assertSame('application/json', explode(';', (string) $requests[0]['contentType'])[0]);
        $this->assertSame(202, $requests[0]['status']);
        $this->assertSame([], $this->sleeps);
    }

    public function testServer500IsRetriedWithBackoffUntilAccepted(): void
    {
        $transport = $this->transport(statusPlan: '500,202');

        $this->assertTrue($transport->send($this->payload()));

        $statuses = array_map(static fn (array $request) => $request['status'], $this->server->requests());
        $this->assertSame([500, 202], $statuses);
        $this->assertSame([1000], $this->sleeps, 'first retry waits one second');
    }

    public function testPersistent5xxExhaustsRetriesThenDrops(): void
    {
        $transport = $this->transport(maxRetries: 3, statusPlan: '503');

        $this->assertFalse($transport->send($this->payload()));

        $this->assertCount(4, $this->server->requests(), 'initial attempt plus three retries');
        $this->assertSame([1000, 2000, 4000], $this->sleeps);
    }

    public function testRateLimit429IsRetried(): void
    {
        $transport = $this->transport(statusPlan: '429,202');

        $this->assertTrue($transport->send($this->payload()));

        $this->assertSame([429, 202], array_map(static fn (array $r) => $r['status'], $this->server->requests()));
        $this->assertSame([1000], $this->sleeps);
    }

    public function testClientErrorsAreNotRetried(): void
    {
        $transport = $this->transport(maxRetries: 3, debug: true, statusPlan: '400');

        $this->assertFalse($transport->send($this->payload()));

        $this->assertCount(1, $this->server->requests(), 'a 400 is permanent — no retry');
        $this->assertSame([], $this->sleeps);
    }

    public function testUnreachableEndpointRetriesNetworkErrorsThenGivesUp(): void
    {
        // Bind and release a port so connection attempts are refused deterministically.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        [$host, $port] = explode(':', $name);

        $this->sleeps = [];
        $transport = new HttpTransport(
            "http://$host:$port/events",
            'pk_transport_test',
            maxRetries: 2,
            timeoutMs: 2000,
            sleepMs: fn (int $ms) => $this->sleeps[] = $ms
        );

        $this->assertFalse($transport->send($this->payload()));
        $this->assertSame([1000, 2000], $this->sleeps);
    }

    public function testPayloadThatCannotEncodeIsDroppedWithoutARequest(): void
    {
        $transport = $this->transport();
        // Invalid UTF-8 makes json_encode fail before anything reaches the network.
        $payload = $this->payload(eventName: "\xB1\x31");

        $this->assertFalse($transport->send($payload));
        $this->assertSame([], $this->server->requests());
    }

    // ---------------------------------------------------------------------- helpers

    private function transport(int $maxRetries = 3, bool $debug = false, string $statusPlan = ''): HttpTransport
    {
        if ($this->server === null) {
            $this->server = LocalIngestServer::start($statusPlan);
        }

        return new HttpTransport(
            $this->server->endpoint(),
            'pk_transport_test',
            $maxRetries,
            5000,
            $debug,
            fn (int $ms) => $this->sleeps[] = $ms
        );
    }

    private function payload(string $eventName = 'transport_test'): BatchPayload
    {
        return new BatchPayload('batch_test', 1_750_000_000_000, [
            new AnalyticsEvent(
                eventId: 'evt_transport_test',
                event: $eventName,
                eventType: EventType::Track,
                anonymousId: 'anon_test',
                sessionId: 'sess_test',
                timestamp: 1_750_000_000_000,
                properties: ['n' => '1'],
                context: new EventContext('test', 'php'),
            ),
        ]);
    }
}
