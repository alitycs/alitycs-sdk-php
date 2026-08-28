<?php

declare(strict_types=1);

namespace Alitycs\Tests\Integration;

use Alitycs\AnalyticsEvent;
use Alitycs\BatchPayload;
use Alitycs\EventContext;
use Alitycs\EventType;
use Alitycs\FileBatchStore;
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

    public function testRateLimited429HonoursTheRetryAfterSecondsHeader(): void
    {
        $transport = $this->transport(maxRetries: 1, statusPlan: '429,202', retryAfterPlan: '2');

        $this->assertTrue($transport->send($this->payload()));

        $this->assertSame([429, 202], array_map(static fn (array $r) => $r['status'], $this->server->requests()));
        // Retry-After: 2 replaces the 1s default backoff for the attempt after the 429.
        $this->assertSame([2000], $this->sleeps);
    }

    public function testRateLimited429HonoursTheRetryAfterHttpDateHeader(): void
    {
        $transport = $this->transport(maxRetries: 1, statusPlan: '429,202', retryAfterPlan: 'date');

        $this->assertTrue($transport->send($this->payload()));

        // The router sends an HTTP-date three seconds out; a couple of hundred ms of
        // wall time may pass between producing and parsing the header.
        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThanOrEqual(2000, $this->sleeps[0]);
        $this->assertLessThanOrEqual(3000, $this->sleeps[0]);
    }

    public function testInvalidRetryAfterFallsBackToExponentialBackoff(): void
    {
        $transport = $this->transport(
            maxRetries: 1,
            statusPlan: '429,202',
            retryAfterPlan: 'not-a-date'
        );

        $this->assertTrue($transport->send($this->payload()));

        $this->assertSame([1000], $this->sleeps);
    }

    public function testHugeRetryAfterIsNotShortenedToClientBackoffCap(): void
    {
        $transport = $this->transport(maxRetries: 1, statusPlan: '429,202', retryAfterPlan: '3600');

        $this->assertTrue($transport->send($this->payload()));

        $this->assertSame([3_600_000], $this->sleeps);
    }

    public function testClientErrorsAreNotRetried(): void
    {
        $transport = $this->transport(maxRetries: 3, debug: true, statusPlan: '400');

        $this->assertFalse($transport->send($this->payload()));

        $this->assertCount(1, $this->server->requests(), 'a 400 is permanent — no retry');
        $this->assertSame([], $this->sleeps);
    }

    public function testRedirect3xxIsTerminalAndNeverRetried(): void
    {
        // A 302 used to fall into the retryable bucket and burn the whole retry budget
        // on an answer that could never change.
        $transport = $this->transport(maxRetries: 5, debug: true, statusPlan: '302');

        $this->assertFalse($transport->send($this->payload()));

        $this->assertCount(1, $this->server->requests(), 'a 3xx is permanent — no retry');
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

    public function testIllFormedUtf8InPayloadIsSubstitutedAndStillDelivered(): void
    {
        // Ill-formed UTF-8 used to fail json_encode and silently drop the whole batch;
        // it is now substituted (U+FFFD) so the batch still delivers.
        $transport = $this->transport();
        $payload = $this->payload(eventName: "\xB1\x31");

        $this->assertTrue($transport->send($payload));
        $requests = $this->server->requests();
        self::assertCount(1, $requests);
        $this->assertSame(202, $requests[0]['status']);
        $event = $requests[0]['body']['events'][0];
        $this->assertSame(1, preg_match('//u', $event['event']), 'delivered event must be valid UTF-8');
    }

    public function testPersistedBatchIsReplayedExactlyAfterRestart(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $first = $this->transport(maxRetries: 0, statusPlan: '500,202', persistencePath: $path);
            $this->assertFalse($first->send($this->payload()));
            $this->assertTrue($first->durableEnabled());
            $this->assertSame(1, $first->pendingDurableEvents());

            $restarted = $this->transport(maxRetries: 0, persistencePath: $path);
            $this->assertTrue($restarted->recover());
            $this->assertSame(0, $restarted->pendingDurableEvents());
            $requests = $this->server->requests();
            $this->assertSame($requests[0]['body'], $requests[1]['body']);
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function testPersistStoresWithoutNetworkThenRecoveryDelivers(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $transport = $this->transport(maxRetries: 0, persistencePath: $path);
            $payload = $this->payload();

            $this->assertTrue($transport->persist($payload));
            $this->assertSame(1, $transport->pendingDurableEvents());
            $this->assertSame([], $this->server->requests(), 'persist must not touch the network');

            $this->assertTrue($transport->recover());
            $this->assertSame(0, $transport->pendingDurableEvents());
            $this->assertSame($payload->batchId, $this->server->requests()[0]['body']['batchId']);
        } finally {
            @unlink($path);
        }
    }

    public function testPersistFailureReturnsFalseWithoutNetwork(): void
    {
        $parentFile = (string) tempnam(sys_get_temp_dir(), 'alitycs-php-parent-file-');
        try {
            $transport = $this->transport(
                maxRetries: 0,
                persistencePath: $parentFile . '/wal.json',
            );

            $this->assertFalse($transport->persist($this->payload()));
            $this->assertSame([], $this->server->requests());
        } finally {
            @unlink($parentFile);
        }
    }

    public function testRestartHonoursPersistedRetryAfterDeadline(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $first = $this->transport(
                maxRetries: 0,
                statusPlan: '429,202',
                retryAfterPlan: '3',
                persistencePath: $path,
            );
            $this->assertFalse($first->send($this->payload()));
            $this->sleeps = [];

            $restarted = $this->transport(maxRetries: 0, persistencePath: $path);
            $this->assertTrue($restarted->recover());
            $this->assertNotEmpty($this->sleeps);
            $this->assertGreaterThanOrEqual(2500, $this->sleeps[0]);
        } finally {
            @unlink($path);
        }
    }

    public function testFailedRecoveryKeepsTheDurableBatchPending(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $first = $this->transport(maxRetries: 0, statusPlan: '500', persistencePath: $path);
            $this->assertFalse($first->send($this->payload()));

            $restarted = $this->transport(maxRetries: 0, persistencePath: $path);
            $this->assertFalse($restarted->recover());
            $this->assertSame(1, $restarted->pendingDurableEvents());
        } finally {
            @unlink($path);
        }
    }

    public function testTerminalRecoveryAcknowledgesAndContinues(): void
    {
        $path = sys_get_temp_dir() . '/alitycs-php-wal-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $store = new FileBatchStore($path);
            $store->put('batch_rejected', '{"batchId":"batch_rejected"}', 1);
            $store->put('batch_healthy', '{"batchId":"batch_healthy"}', 1);
            $restarted = $this->transport(maxRetries: 0, statusPlan: '400,202', persistencePath: $path);

            $this->assertTrue($restarted->recover());
            $this->assertSame(0, $restarted->pendingDurableEvents());
            $this->assertCount(2, $this->server->requests());
        } finally {
            @unlink($path);
        }
    }

    // ---------------------------------------------------------------------- helpers

    private function transport(
        int $maxRetries = 3,
        bool $debug = false,
        string $statusPlan = '',
        string $retryAfterPlan = '',
        ?string $persistencePath = null,
    ): HttpTransport {
        if ($this->server === null) {
            $this->server = LocalIngestServer::start($statusPlan, $retryAfterPlan);
        }

        return new HttpTransport(
            $this->server->endpoint(),
            'pk_transport_test',
            $maxRetries,
            5000,
            $debug,
            fn (int $ms) => $this->sleeps[] = $ms,
            $persistencePath,
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
