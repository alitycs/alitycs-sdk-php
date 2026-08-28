# alitycs-sdk-php

Alitycs Analytics SDK for PHP server applications. Events are batched and delivered to
the Alitycs ingest endpoint over HTTPS, validating against the shared event contract
(`specs/event-schema.json` v0.4.0).

Requires PHP >= 8.1 with `ext-curl` and `ext-json`. No other runtime dependencies.

```bash
composer require alitycs/alitycs-sdk-php
```

## Quickstart

```php
use Alitycs\Client;
use Alitycs\RevenuePayload;

$alitycs = new Client('pk_live_…', [
    'endpoint' => 'https://api.alitycs.com/events',
    'flushSize' => 20,
]);

$alitycs->identify('usr_1842', ['plan' => 'pro']);
$alitycs->track('signup_completed', ['plan' => 'free']);
$alitycs->page('Dashboard');
$alitycs->captureError('checkout_failed', ['code' => 'E_CARD']);
$alitycs->trackRevenue(RevenuePayload::transaction('fact_1', '19.99', 'USD'));
$alitycs->setGlobalProperties(['suite' => 'checkout']);
$alitycs->reset();          // rotates session + anonymous id, clears the user

$alitycs->shutdown();       // or let script shutdown deliver the remainder
```

## There is no background flusher

**PHP has no background threads**, so this SDK has no timer-based flush. Queued events
are delivered by exactly three triggers:

1. **Queue depth** — when the queue reaches `flushSize`.
2. **Explicit `flush()`** — sends everything currently queued as one batch.
3. **Script shutdown** — via `register_shutdown_function()`, so a request that ends
   without an explicit flush still delivers.

`shutdown()` drains fully and never loses queued events; it is safe to call more than
once. After it returns, every enqueued event has been sent or permanently dropped under
the retry policy below. Events enqueued after `shutdown()` are ignored (logged in debug
mode), because there is nothing left to deliver them.

`flushInterval` exists for API parity but is **opportunistic**: it is never a timer. It
is checked only while events keep arriving — each enqueue compares elapsed wall time
against the interval and flushes if it has elapsed. A request whose final event arrives
before the interval does will not be sent until `flush()`/`shutdown()` or the next
event. Set it to `0` to disable the check entirely.

In long-running processes (workers, daemons), call `flush()` on your own cadence — do
not rely on request lifecycle hooks that never fire.

## Long-running workers (Octane, Swoole, RoadRunner)

A single `Client` instance kept alive across many logical requests carries state:
`identify()`'s user id, `setGlobalProperties()`, and the session/anonymous ids. Without a
per-request reset that state leaks into the next request's events — event A's user gets
credited for request B's actions.

Call `resetForRequest()` at the top of every logical request, before tracking anything:

```php
// e.g. Laravel Octane: in a middleware or the ListenForEventsTick listener
$app->middleware(function ($request, $next) use ($alitycs) {
    $alitycs->resetForRequest();   // clears user id, global properties, session identity
    return $next($request);
});
```

When logical requests can overlap on one client, pass a named per-call `userId` instead
of mutating shared identity. The override is captured only for that event:

```php
$alitycs->track('checkout_started', userId: $request->userId);
$alitycs->captureError('checkout_failed', ['code' => 'E_CARD'], userId: $request->userId);
```

The same optional argument is available on `trackRevenue()` and `page()`.

Queued but unsent events are **not** dropped by `resetForRequest()`; flush beforehand if
they should be delivered before the identities change. For Swoole coroutine workers,
prefer per-call `userId` values; resetting shared ambient state while requests overlap is
not safe.

## Configuration

| Option | Default | Meaning |
|---|---|---|
| `endpoint` | `https://api.alitycs.com/events` | Ingest URL |
| `flushSize` | `25` | Queue depth that triggers a send |
| `flushInterval` | `10.0` | Opportunistic seconds between flushes; `0` disables |
| `maxQueueSize` | `1000` | Buffered events before new arrivals are dropped |
| `maxRetries` | `3` | Attempts after the first (5xx / 429 / network errors only) |
| `timeoutMs` | `10000` | Per-request HTTP timeout |
| `sessionTimeout` | `1800000` | Inactivity (ms) before the session id rotates |
| `debug` | `false` | Log diagnostics to stderr under `[Alitycs]` |
| `batching` | `true` | When `false`, every event is its own single-event batch |
| `persistencePath` | `null` | Optional exact in-flight batch WAL path for restart recovery |

Unknown option names throw — a typo fails loudly instead of running on defaults.

## Retry policy

Batches are retried with exponential backoff (1s, 2s, 4s … capped at 10s) on `5xx`,
`429`, and network errors, up to `maxRetries` times. Any other `4xx` is permanent and is
never retried. Delivery failures are logged, never thrown — a dead endpoint cannot fatal
your application. Without persistence, an exhausted transient batch is lost. With
`persistencePath`, the exact serialized batch is atomically retained and the next process replays
it on `flush()`/`shutdown()`, including any remaining `Retry-After` pause. Terminal responses
acknowledge and remove it. The WAL covers batches that reached transport, not events still waiting
in the PHP queue; configure one process owner per path.

## Revenue

`trackRevenue()` requires a secret key with `revenue:write`. Payloads are built through
named constructors matching the wire contract's three variants:
`RevenuePayload::transaction(...)`, `::mrrSnapshot(...)`, and `::mrrBaselineComplete(...)`.
Invalid combinations of fields for a variant cannot be constructed.

## Not in scope

Feature flags, session recording, group analytics, and log ingestion are not Alitycs
capabilities and are deliberately absent here. The PHP SDK is server-class: it has no
autocapture — call `page()` explicitly.

## Development

```bash
composer install
vendor/bin/phpunit                       # unit + integration suite
composer run test:coverage               # suite + coverage gate (lines >= 90%, methods >= 85%)
composer run conformance                 # conformance app against $CAPTURE_URL
```
