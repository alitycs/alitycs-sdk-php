<?php

declare(strict_types=1);

namespace Alitycs\Tests\Integration;

use Alitycs\Client;
use Alitycs\RevenuePayload;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end client behaviour against a real HTTP listener: batching, identity,
 * global properties, revenue, retry, and the shutdown drain.
 */
final class ClientIntegrationTest extends TestCase
{
    private ?LocalIngestServer $server = null;

    private ?Client $client = null;

    protected function tearDown(): void
    {
        // Every client must be closed in-test so its terminal shutdown callback is a
        // cheap no-op instead of a retry storm against a stopped listener.
        $this->client?->shutdown();
        $this->client = null;

        if ($this->server !== null) {
            $this->server->stop();
            $this->server = null;
        }
    }

    public function testBatchesSendWireShapedEventsWithTheRightHeaders(): void
    {
        $this->startClient(['flushSize' => 2]);

        $this->client->identify('usr_conf_1', ['plan' => 'pro']);
        $this->client->track('signup_completed', ['n' => 1]);

        $requests = $this->server->requests();
        self::assertCount(1, $requests);

        $this->assertSame('Bearer pk_client_test', $requests[0]['authorization']);
        $this->assertSame('application/json', explode(';', (string) $requests[0]['contentType'])[0]);

        [$identify, $track] = $requests[0]['body']['events'];

        $this->assertSame('identify', $identify['event']);
        $this->assertSame('identify', $identify['eventType']);
        $this->assertSame('usr_conf_1', $identify['userId']);
        $this->assertSame('usr_conf_1', $identify['properties']['userId']);
        $this->assertSame('pro', $identify['properties']['plan']);

        $this->assertSame('signup_completed', $track['event']);
        $this->assertSame('track', $track['eventType']);
        $this->assertSame('1', $track['properties']['n']);
        $this->assertSame($identify['userId'], $track['userId']);

        foreach ([$identify, $track] as $event) {
            $this->assertStringStartsWith('evt_', $event['eventId']);
            $this->assertStringStartsWith('anon_', $event['anonymousId']);
            $this->assertStringStartsWith('sess_', $event['sessionId']);
            $this->assertIsInt($event['timestamp']);
            $this->assertSame('php', $event['context']['sdkLanguage']);
            $this->assertSame(Client::SDK_VERSION, $event['context']['sdkVersion']);
            $this->assertArrayHasKey('timezone', $event['context']);
        }
        $this->assertSame($identify['anonymousId'], $track['anonymousId']);
        $this->assertSame($identify['sessionId'], $track['sessionId']);
        $this->assertCount(2, $requests[0]['body']['events'], 'both events travel in one batch');
        $this->assertMatchesRegularExpression('/^batch_[0-9a-f-]{36}$/', $requests[0]['body']['batchId']);
        $this->assertIsInt($requests[0]['body']['sentAt']);
    }

    public function testResetRotatesIdentityAndDropsUserIdFromSubsequentEvents(): void
    {
        $this->startClient();

        $this->client->identify('usr_before', ['plan' => 'pro']);
        $this->client->track('before_reset');
        $this->client->flush();

        $this->client->reset();
        $this->client->track('after_reset');
        $this->client->flush();

        $requests = $this->server->requests();
        self::assertCount(2, $requests);

        [$beforeIdentify, $beforeTrack] = $requests[0]['body']['events'];
        [$afterTrack] = $requests[1]['body']['events'];

        $this->assertSame($beforeIdentify['anonymousId'], $beforeTrack['anonymousId']);
        $this->assertSame($beforeIdentify['sessionId'], $beforeTrack['sessionId']);

        $this->assertNotSame($beforeTrack['anonymousId'], $afterTrack['anonymousId']);
        $this->assertNotSame($beforeTrack['sessionId'], $afterTrack['sessionId']);
        $this->assertArrayNotHasKey('userId', $afterTrack);
    }

    public function testGlobalPropertiesMergeOverrideAndRemove(): void
    {
        $this->startClient();

        $this->client->setGlobalProperties(['suite' => 'integration', 'channel' => 'web']);
        $this->client->track('with_globals');
        $this->client->track('with_override', ['suite' => 'overridden']);
        $this->client->removeGlobalProperties(['channel']);
        $this->client->track('without_channel');
        $this->client->clearGlobalProperties();
        $this->client->track('after_clear');
        $this->client->flush();

        $events = $this->server->requests()[0]['body']['events'];

        $this->assertSame(['suite' => 'integration', 'channel' => 'web'], $events[0]['properties']);
        $this->assertSame(['suite' => 'overridden', 'channel' => 'web'], $events[1]['properties']);
        $this->assertSame(['suite' => 'integration'], $events[2]['properties']);
        $this->assertSame([], $events[3]['properties'] ?? []);

        $globals = $this->client->getGlobalProperties();
        $this->assertSame([], $globals);
    }

    public function testGetGlobalPropertiesReturnsCurrentState(): void
    {
        $this->startClient();

        $this->client->setGlobalProperties(['a' => 1]);
        $this->client->setGlobalProperties(['b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $this->client->getGlobalProperties());
    }

    public function testRevenueEventCarriesTheTransactionPayload(): void
    {
        $this->startClient();

        $this->client->trackRevenue(
            RevenuePayload::transaction('fact_' . bin2hex(random_bytes(4)), '19.99', 'USD'),
            ['order' => '1']
        );
        $this->client->flush();

        $event = $this->server->requests()[0]['body']['events'][0];

        $this->assertSame('revenue_transaction', $event['event']);
        $this->assertSame('track', $event['eventType']);
        $this->assertSame([
            'version' => 1,
            'kind' => 'transaction',
            'factId' => $event['revenue']['factId'],
            'currency' => 'USD',
            'amount' => '19.99',
        ], $event['revenue']);
        $this->assertSame('1', $event['properties']['order']);
    }

    public function testPageNamesDefaultAndExplicit(): void
    {
        $this->startClient();

        $this->client->page();
        $this->client->page('Dashboard', ['section' => 'reports']);
        $this->client->flush();

        $events = $this->server->requests()[0]['body']['events'];

        $this->assertSame('page_view', $events[0]['event']);
        $this->assertSame('page', $events[0]['eventType']);
        $this->assertSame('Dashboard', $events[1]['event']);
        $this->assertSame('reports', $events[1]['properties']['section']);
    }

    public function testBlankNamesAreIgnoredWithoutSendingAnything(): void
    {
        $this->startClient();

        $this->client->track('');
        $this->client->captureError('');
        $this->client->identify('');
        $this->client->flush();

        $this->assertSame([], $this->server->requests());
    }

    public function testCaptureErrorEmitsAnErrorEvent(): void
    {
        $this->startClient();

        $this->client->captureError('checkout_failed', ['code' => 'E_TEST']);
        $this->client->flush();

        $event = $this->server->requests()[0]['body']['events'][0];

        $this->assertSame('checkout_failed', $event['event']);
        $this->assertSame('error', $event['eventType']);
        $this->assertSame('E_TEST', $event['properties']['code']);
    }

    public function testShutdownDeliversEverythingStillQueuedExactlyOnce(): void
    {
        $this->startClient(); // flushSize stays 25 so nothing auto-sends

        for ($i = 0; $i < 6; $i++) {
            $this->client->track("drain_$i");
        }
        $this->assertSame([], $this->server->requests());
        $this->assertSame(6, $this->client->pending());

        $this->client->shutdown();

        $this->assertSame(0, $this->client->pending());
        $events = $this->server->requests()[0]['body']['events'];
        $names = array_map(static fn (array $event) => $event['event'], $events);
        $this->assertSame(['drain_0', 'drain_1', 'drain_2', 'drain_3', 'drain_4', 'drain_5'], $names);
    }

    public function testEnqueueAfterShutdownIsIgnored(): void
    {
        $this->startClient();

        $this->client->shutdown();
        $this->client->track('too_late');
        $this->client->flush();

        $this->assertSame(0, $this->client->pending());
        $this->assertSame([], $this->server->requests());
    }

    public function testFlushOnAnEmptyQueueSendsNothing(): void
    {
        $this->startClient();

        $this->client->flush();

        $this->assertSame([], $this->server->requests());
    }

    public function testBatchingDisabledSendsEachEventImmediately(): void
    {
        $this->startClient(['batching' => false]);

        $this->client->track('solo_one');
        $this->client->track('solo_two');

        $this->assertSame(0, $this->client->pending());
        $requests = $this->server->requests();
        $this->assertCount(2, $requests);
        $this->assertSame('solo_one', $requests[0]['body']['events'][0]['event']);
        $this->assertSame('solo_two', $requests[1]['body']['events'][0]['event']);
    }

    /**
     * The conformance-shaped scenario: three groups of two events at flushSize=2 with
     * the server rejecting exactly request 3. The failed batch must be retried under the
     * same batchId and every event must arrive exactly once.
     */
    public function testFailedBatchIsRetriedUnderTheSameIdAndNothingIsLostOrDuplicated(): void
    {
        $this->startClient(['flushSize' => 2, 'maxRetries' => 3], statusPlan: '202,202,500,202');

        $groups = [
            [['name' => 'group1_a'], ['name' => 'group1_b']],
            [['name' => 'group2_a'], ['name' => 'group2_b']],
            [['name' => 'group3_a'], ['name' => 'group3_b']],
        ];
        foreach ($groups as $group) {
            foreach ($group as ['name' => $name]) {
                $this->client->track($name);
            }
            $this->client->flush();
        }

        $requests = $this->server->requests();
        $statuses = array_map(static fn (array $request) => $request['status'], $requests);
        $this->assertSame([202, 202, 500, 202], $statuses);

        $this->assertSame(
            $requests[2]['body']['batchId'],
            $requests[3]['body']['batchId'],
            'the retried batch keeps its identity'
        );

        $delivered = [];
        foreach ($requests as $sequence => $request) {
            if ($request['status'] >= 300) {
                continue;
            }
            foreach ($request['body']['events'] as $event) {
                $delivered[] = $event['event'];
            }
        }

        $this->assertCount(6, $delivered);
        $this->assertCount(6, array_unique($delivered), 'no event may arrive twice');
        sort($delivered);
        $this->assertSame(
            ['group1_a', 'group1_b', 'group2_a', 'group2_b', 'group3_a', 'group3_b'],
            $delivered
        );
    }

    public function testInvalidUtf8PropertyStillDelivers(): void
    {
        // Ill-formed UTF-8 must not fail JSON encoding and drop the batch; it is
        // sanitized (U+FFFD substitution) so the event still arrives.
        $this->startClient();

        $this->client->track('utf8_broken', ['data' => "\xB1\x31"]);
        $this->client->flush();

        $requests = $this->server->requests();
        self::assertCount(1, $requests);
        $this->assertSame(202, $requests[0]['status']);
        $event = $requests[0]['body']['events'][0];
        $this->assertSame('utf8_broken', $event['event']);
        $this->assertArrayHasKey('data', $event['properties']);
        $this->assertSame(1, preg_match('//u', $event['properties']['data']));
    }

    public function testWholeBatch400SplitsAndDeliversEveryEvent(): void
    {
        // The server rejects an entire batch when one event violates a limit, so a
        // refused payload is split in half and each half retried until everything lands.
        $this->startClient(statusPlan: '400,202,202');

        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->client->track("split_$name");
        }
        $this->client->flush();

        $requests = $this->server->requests();
        $statuses = array_map(static fn (array $request) => $request['status'], $requests);
        $this->assertSame([400, 202, 202], $statuses);

        $delivered = [];
        foreach ($requests as $request) {
            if ($request['status'] >= 300) {
                continue;
            }
            foreach ($request['body']['events'] as $event) {
                $delivered[] = $event['event'];
            }
        }
        sort($delivered);
        $this->assertSame(
            ['split_a', 'split_b', 'split_c', 'split_d'],
            $delivered
        );
        $this->assertSame(4, $this->client->getBatchManager()->deliveredTotal());
        $this->assertSame(0, $this->client->getBatchManager()->lostTotal());
    }

    public function testOversizedPropertyIsRejectedLocallyWithoutSending(): void
    {
        $this->startClient();

        $this->client->track('too_big', ['payload' => str_repeat('x', 1001)]);
        $this->client->track('healthy', ['n' => '1']);

        $this->assertSame(1, $this->client->pending(), 'only the healthy event may be queued');
        $this->assertSame(1, $this->client->rejectedLocally());
        $this->client->flush();

        $requests = $this->server->requests();
        self::assertCount(1, $requests);
        $this->assertSame(['healthy'], array_column($requests[0]['body']['events'], 'event'));
    }

    // ---------------------------------------------------------------------- helpers

    private function startClient(array $options = [], string $statusPlan = ''): Client
    {
        if ($this->server === null) {
            $this->server = LocalIngestServer::start($statusPlan);
        }

        return $this->client = new Client('pk_client_test', array_merge([
            'endpoint' => $this->server->endpoint(),
            'flushInterval' => 0,
            'maxRetries' => 0,
            'timeoutMs' => 5000,
        ], $options));
    }
}
