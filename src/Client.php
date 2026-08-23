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
 * called, or at script shutdown via `register_shutdown_function()` — whichever comes
 * first. `shutdown()` drains fully and never loses queued events.
 *
 * Not in scope: feature flags, session recording, group analytics, autocapture. The
 * PHP SDK is server-class — it has no `page` autocapture; call `page()` explicitly.
 */
final class Client
{
    public const SDK_VERSION = '1.0.0';

    private readonly Config $config;
    private readonly HttpTransport $transport;
    private readonly SessionManager $sessionManager;
    private readonly ?BatchManager $batchManager;

    private ?string $userId = null;

    /** @var array<string, mixed> */
    private array $globalProperties = [];

    private bool $closed = false;

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
        );
        $this->sessionManager = new SessionManager($this->config->sessionTimeout);
        $this->batchManager = $this->config->batching
            ? new BatchManager($this->config, $this->transport->send(...))
            : null;

        // A request that ends without an explicit flush must still deliver its events.
        register_shutdown_function(function (): void {
            try {
                $this->shutdown();
            } catch (\Throwable $throwable) {
                Log::write(true, 'Shutdown flush failed: ' . $throwable->getMessage());
            }
        });
    }

    /** @param array<string, mixed> $properties */
    public function track(string $eventName, array $properties = []): void
    {
        if ($eventName === '') {
            return;
        }

        $this->enqueue(EventType::Track, $eventName, $properties);
    }

    /**
     * Trusted revenue ingestion — requires a secret key with `revenue:write`.
     *
     * @param array<string, mixed> $properties
     */
    public function trackRevenue(RevenuePayload $payload, array $properties = []): void
    {
        $this->enqueue(EventType::Track, 'revenue_' . $payload->kind, $properties, $payload);
    }

    /** @param array<string, mixed> $properties */
    public function captureError(string $errorName, array $properties = []): void
    {
        if ($errorName === '') {
            return;
        }

        $this->enqueue(EventType::Error, $errorName, $properties);
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
    public function page(?string $name = null, array $properties = []): void
    {
        $pageName = $name === null || $name === '' ? 'page_view' : $name;

        $this->enqueue(EventType::Page, $pageName, $properties);
    }

    /** Rotates session and anonymous ids and clears the identified user. */
    public function reset(): void
    {
        $this->userId = null;
        $this->sessionManager->reset();
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
        if ($this->closed) {
            return;
        }

        $this->batchManager?->flush();
    }

    /**
     * Stops accepting events and delivers everything still queued — after it returns,
     * every enqueued event has been sent or permanently dropped by the transport's retry
     * policy. Idempotent; also invoked automatically at script shutdown.
     */
    public function shutdown(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // Drain rather than flush-once so nothing can remain queued afterwards.
        while ($this->batchManager !== null && $this->batchManager->pending() > 0) {
            $this->batchManager->flush();
        }
    }

    /** Events accepted but not yet sent. */
    public function pending(): int
    {
        return $this->batchManager?->pending() ?? 0;
    }

    /** @param array<string, mixed>|null $properties */
    private function enqueue(EventType $type, string $name, ?array $properties, ?RevenuePayload $revenue = null): void
    {
        if ($this->closed) {
            Log::write($this->config->debug, "Client already shut down — \"$name\" event dropped");

            return;
        }

        $this->sessionManager->touch();
        $session = $this->sessionManager->getSession();

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
            userId: $this->userId,
            revenue: $revenue,
        );

        if ($this->batchManager !== null) {
            $this->batchManager->add($event);

            return;
        }

        // Batching disabled: every event travels in its own single-event batch.
        $this->transport->send(
            new BatchPayload('batch_' . Utils::generateId(), Utils::nowMs(), [$event])
        );
    }
}
