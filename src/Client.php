<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Alitycs analytics client for PHP server applications.
 *
 * ```php
 * $alitycs = new \Alitycs\Client('pk_live_…');
 * $alitycs->identify('usr_1842', ['plan' => 'pro']);
 * $alitycs->track('signup_completed', ['plan' => 'free']);
 * $alitycs->shutdown(); // or let script shutdown deliver the remainder
 * ```
 *
 * **Flush triggers.** PHP has no background threads, so there is no timer-based flush.
 * Queued events are delivered when the queue reaches `flushSize`, when `flush()` is
 * called, or at script shutdown by a single process-wide shutdown hook that drains every
 * live client — whichever comes first. `shutdown()` drains fully and never loses queued
 * events.
 *
 * **Fork safety.** A child created after a client exists (`pcntl_fork()`, or any other
 * fork) never inherits the parent's queue: the first SDK call in the child detects the
 * new process id, drops inherited-but-unsent events (the parent delivers them), and
 * rotates the session id. Call {@see resetForChildProcess()} explicitly to do it eagerly.
 *
 * Not in scope: feature flags, session recording, group analytics, autocapture. The
 * PHP SDK is server-class — it has no `page` autocapture; call `page()` explicitly.
 */
final class Client
{
    public const SDK_VERSION = '1.0.1';

    private readonly Config $config;
    private readonly HttpTransport $transport;
    private readonly SessionManager $sessionManager;
    private readonly ?BatchManager $batchManager;

    private ?string $userId = null;

    /** @var array<string, mixed> */
    private array $globalProperties = [];

    private int $rejectedLocally = 0;

    private bool $closed = false;

    /**
     * Clients that still need delivery at script shutdown, in creation order. Weakly
     * held: an explicitly shut-down or garbage-collected client leaves the registry by
     * itself instead of pinning the instance (and its queue) until process exit.
     */
    private static ?\WeakMap $liveClients = null;

    /** Whether the single process-wide shutdown handler has been registered. */
    private static bool $shutdownHandlerRegistered = false;

    /** Process id of the creator — a different current pid means we are in a forked child. */
    private static ?int $creatorPid = null;

    /**
     * @param string $apiKey publishable key (secret key required for trackRevenue)
     * @param array<string, mixed> $options see {@see Config} for every key
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->config = new Config($apiKey, $options);
        $this->transport = new HttpTransport(
            endpoint: $this->config->endpoint,
            apiKey: $this->config->apiKey(),
            maxRetries: $this->config->maxRetries,
            timeoutMs: $this->config->timeoutMs,
            debug: $this->config->debug,
            persistencePath: $this->config->persistencePath,
            maxPendingEvents: $this->config->maxQueueSize,
        );
        $this->sessionManager = new SessionManager($this->config->sessionTimeout);
        $this->batchManager = $this->config->batching
            ? new BatchManager(
                config: $this->config,
                send: $this->transport->sendWithOutcome(...),
                recover: $this->transport->recover(...),
                durablePending: $this->transport->pendingDurableEvents(...),
                durable: $this->transport->durableEnabled(),
                persist: $this->transport->persist(...),
            )
            : null;

        self::$liveClients ??= new \WeakMap();
        self::$creatorPid ??= getmypid() ?: null;
        self::$liveClients[$this] = true;

        // One process-wide handler iterates the live clients; registering per instance
        // would accumulate one closure per client for the lifetime of the process.
        if (!self::$shutdownHandlerRegistered) {
            self::$shutdownHandlerRegistered = true;
            // A request that ends without an explicit flush must still deliver its events.
            register_shutdown_function(static function (): void {
                self::flushAllAtShutdown();
            });
        }
    }

    /**
     * Post-fork repair, run in the child process only: every live client drops the queue
     * it inherited across the fork (the parent still owns delivering those events —
     * sending them again here would double-count each one) and rotates its session id.
     *
     * Called automatically the first time the child touches any SDK entry point; PHP's
     * pcntl extension exposes no at-fork hook to register against, so long-running
     * children may also invoke this right after `pcntl_fork()` returns 0. When pcntl is
     * absent nothing is lost: the lazy pid check covers forks from any source. A no-op
     * when the current process created the clients.
     */
    public static function resetForChildProcess(): void
    {
        $pid = getmypid();
        if ($pid === false) {
            return;
        }

        self::$creatorPid = $pid;
        foreach (self::snapshotLiveClients() as $client) {
            $client->resetForChild();
        }
    }

    /** Instance half of {@see resetForChildProcess()}. */
    private function resetForChild(): void
    {
        $this->batchManager?->resetForChild();
        $this->transport->resetForChild();
        $this->sessionManager->resetForChild();
    }

    /**
     * Re-anchors the SDK to the current process after a fork. Cheap (one pid compare on
     * the hot path) and runs before any state is read or sent.
     */
    private function adoptCurrentProcess(): void
    {
        $pid = getmypid();
        if ($pid !== false && self::$creatorPid !== null && $pid !== self::$creatorPid) {
            self::resetForChildProcess();
        }
    }

    /** The single registered shutdown hook: drains every client still alive. */
    private static function flushAllAtShutdown(): void
    {
        foreach (self::snapshotLiveClients() as $client) {
            try {
                $client->shutdown();
            } catch (\Throwable $throwable) {
                Log::write($client->config->debug, 'Shutdown flush failed: ' . $throwable->getMessage());
            }
        }
    }

    /**
     * The live clients as a plain list — snapshotting keeps iteration safe while
     * `shutdown()` deregisters clients from the same map.
     *
     * @return list<self>
     */
    private static function snapshotLiveClients(): array
    {
        if (!isset(self::$liveClients)) {
            return [];
        }

        $clients = [];
        foreach (self::$liveClients as $client => $registered) {
            $clients[] = $client;
        }

        return $clients;
    }

    /** @param array<string, mixed> $properties */
    public function track(string $eventName, array $properties = [], ?string $userId = null): void
    {
        if ($eventName === '') {
            return;
        }

        $this->enqueue(EventType::Track, $eventName, $properties, userId: $userId);
    }

    /**
     * Trusted revenue ingestion — requires a secret key with `revenue:write`.
     *
     * @param array<string, mixed> $properties
     */
    public function trackRevenue(
        RevenuePayload $payload,
        array $properties = [],
        ?string $userId = null,
    ): void
    {
        $this->enqueue(EventType::Track, 'revenue_' . $payload->kind, $properties, $payload, $userId);
    }

    /** @param array<string, mixed> $properties */
    public function captureError(string $errorName, array $properties = [], ?string $userId = null): void
    {
        if ($errorName === '') {
            return;
        }

        $this->enqueue(EventType::Error, $errorName, $properties, userId: $userId);
    }

    /**
     * Attaches a user id to this session and emits an identify event.
     *
     * @param array<string, mixed> $traits
     */
    public function identify(string $userId, array $traits = []): void
    {
        if ($userId === '') {
            return;
        }

        $this->userId = $userId;
        $this->sessionManager->setUserId($userId);
        // Traits may intentionally override the default `userId` property, matching the
        // JS and JVM SDKs.
        $this->enqueue(EventType::Identify, 'identify', array_merge(['userId' => $userId], $traits));
    }

    /** Emits a page view; servers pass the page name explicitly. */
    public function page(?string $name = null, array $properties = [], ?string $userId = null): void
    {
        $pageName = $name === null || $name === '' ? 'page_view' : $name;

        $this->enqueue(EventType::Page, $pageName, $properties, userId: $userId);
    }

    /** Rotates session and anonymous ids and clears the identified user. */
    public function reset(): void
    {
        $this->userId = null;
        $this->sessionManager->reset();
    }

    /**
     * Prepares a reused client instance for a new logical request.
     *
     * Long-running workers (Laravel Octane, Swoole, RoadRunner…) keep one `Client`
     * alive across many logical requests. Without a per-request reset the previous
     * request's `identify()` user, `setGlobalProperties()`, and session identity stay
     * attached to the next request's events — cross-user misattribution. Call this at
     * the top of every request (after resolving the current user) to clear all three:
     *
     * ```php
     * // e.g. Laravel Octane middleware / worker boot
     * $alitycs->resetForRequest();
     * ```
     *
     * Queued but unsent events from the previous request are NOT dropped — flush
     * beforehand if they should be attributed before the identities change.
     */
    public function resetForRequest(): void
    {
        $this->reset();
        $this->globalProperties = [];
    }

    /** Merged into every subsequent event's properties (call-level keys win). */
    public function setGlobalProperties(array $properties): void
    {
        foreach ($properties as $key => $value) {
            $this->globalProperties[(string) $key] = $value;
        }
    }

    /** @return array<string, mixed> */
    public function getGlobalProperties(): array
    {
        return $this->globalProperties;
    }

    /** @param list<string> $keys */
    public function removeGlobalProperties(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->globalProperties[$key]);
        }
    }

    public function clearGlobalProperties(): void
    {
        $this->globalProperties = [];
    }

    /** Sends everything currently queued as one batch. Never throws. */
    public function flush(): void
    {
        $this->adoptCurrentProcess();

        if ($this->closed) {
            return;
        }

        if ($this->batchManager !== null) {
            $this->batchManager->flush();
        } else {
            $this->transport->recover();
        }
    }

    /**
     * Stops accepting events and resolves everything still queued — after it returns,
     * every enqueued event has been sent, durably retained, or permanently dropped and
     * counted by the transport's retry policy. Idempotent; also invoked automatically at
     * script shutdown.
     */
    public function shutdown(): void
    {
        // Before draining: a forked child must not re-send the queue it inherited.
        $this->adoptCurrentProcess();

        if ($this->closed) {
            return;
        }

        $this->closed = true;
        if (isset(self::$liveClients)) {
            unset(self::$liveClients[$this]);
        }

        // One attempt drains the in-memory queue. Durable transient batches remain on
        // disk for a later process instead of making shutdown loop forever.
        if ($this->batchManager !== null) {
            $this->batchManager->shutdown();
        } else {
            $this->transport->recover();
        }
    }

    /** Events accepted but not yet sent. */
    public function pending(): int
    {
        $this->adoptCurrentProcess();

        return $this->batchManager?->pending() ?? $this->transport->pendingDurableEvents();
    }

    /**
     * Events rejected at build time for violating ingestion limits (also logged at warn
     * level when they happen).
     */
    public function rejectedLocally(): int
    {
        return $this->rejectedLocally;
    }

    /** Delivery counters (`deliveredTotal()` / `lostTotal()`), null without batching. */
    public function getBatchManager(): ?BatchManager
    {
        return $this->batchManager;
    }

    /** @param array<string, mixed>|null $properties */
    private function enqueue(
        EventType $type,
        string $name,
        ?array $properties,
        ?RevenuePayload $revenue = null,
        ?string $userId = null,
    ): void
    {
        $this->adoptCurrentProcess();

        if ($this->closed) {
            Log::write($this->config->debug, "Client already shut down — \"$name\" event dropped");

            return;
        }

        $this->sessionManager->touch();
        $session = $this->sessionManager->getSession();

        // Capture a provided identity for this event without mutating ambient client
        // state. Shared long-lived server clients can therefore interleave requests.
        $effectiveUserId = $userId !== null
            ? (trim($userId) === '' ? null : $userId)
            : $this->userId;

        try {
            $event = new AnalyticsEvent(
                eventId: 'evt_' . Utils::generateId(),
                event: $name,
                eventType: $type,
                anonymousId: $session->anonymousId,
                sessionId: $session->id,
                timestamp: Utils::nowMs(),
                properties: Utils::serializeProperties(
                    array_merge($this->globalProperties, $properties ?? [])
                ),
                context: Context::collect(self::SDK_VERSION),
                userId: $effectiveUserId,
                revenue: $revenue,
            );
            Utils::validateEvent($event);
        } catch (EventRejectedError $error) {
            // Rejected locally: never queued, never sent — the server would refuse the
            // entire batch. Surfaced at warn level and counted, never truncated.
            $this->rejectedLocally++;
            Log::warn($error->getMessage());

            return;
        }

        if ($this->batchManager !== null) {
            $this->batchManager->add($event);

            return;
        }

        // Batching disabled: every event travels in its own single-event batch.
        $delivered = $this->transport->send(
            new BatchPayload('batch_' . Utils::generateId(), Utils::nowMs(), [$event])
        );
        if (!$delivered) {
            Log::warn("Event {$event->eventId} could not be delivered");
        }
    }
}
