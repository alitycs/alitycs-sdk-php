# Changelog

This project follows [Semantic Versioning](https://semver.org/). User-visible changes are recorded
here before a version tag is created.

## [Unreleased]

### Added
- Fork safety: a child process created after a client exists (`pcntl_fork()` or any other fork)
  no longer inherits the parent's queue. The first SDK call in the child detects the new process
  id, drops inherited-but-unsent events (the parent still delivers them — previously both
  processes sent them, double-counting every one), and rotates onto its own session id while
  keeping the anonymous identity and global properties. `Client::resetForChildProcess()` runs it
  eagerly; no extension is required, so forks stay safe even where ext-pcntl is absent.
- `Config` now rejects `maxQueueSize` < `flushSize` with an `InvalidArgumentException`. Below the
  flush threshold the queue started dropping new arrivals before a size-triggered send could ever
  fire, silently degrading batching into drop-everything.
- `Client::resetForRequest()` for long-running workers (Laravel Octane, Swoole, RoadRunner) that
  keep one client instance alive across many logical requests. It clears the identified user id,
  global properties, and session/anonymous identity so a previous request's identity can no
  longer be attributed to the next request's events. Queued but unsent events are not dropped.
  See "Long-running workers" in the README.
- A 429 response's `Retry-After` header (delta-seconds or HTTP-date) is now honoured: the retry
  after it waits at least that long instead of the default backoff, still capped at 10s.
  Previously the header was ignored and rate-limited clients hammered through the rate limit.
- Client-side enforcement of the canonical ingestion limits (identical to the server's
  `EventValidator`): ≤50 properties per event, property keys ≤100 chars, values ≤1000 chars,
  estimated event size ≤64KB, non-blank action plus `userId`/`anonymousId` required, epoch-millis
  timestamps (seconds-scale values rejected), age ≤7 days and never in the future. Violating events
  are rejected locally at build time — never queued, never sent — surfaced with a warn-level log
  (never debug-gated) and the new `Client::rejectedLocally()` counter. User data is never truncated
  silently.
- Ill-formed UTF-8 in property keys or values is sanitized (invalid byte sequences become U+FFFD)
  instead of failing JSON encoding and dropping the whole batch. Both serialization layers
  (`Utils::encodeJson` and `HttpTransport::send`) now pass `JSON_INVALID_UTF8_SUBSTITUTION`.
- Split-on-rejection: `BatchManager` uses the boolean returned by the send closure. A refused
  payload is split in half and each half re-sent recursively so valid events still land when one
  invalid event poisons a batch; an event the server still refuses individually is dropped loudly
  (warn log + new `BatchManager::lostTotal()` counter) because re-queueing it would poison every
  future batch.
- Delivery counters on `BatchManager`: `deliveredTotal()`, `lostTotal()`.
- `CHANGELOG.md` (this file) did not exist.

### Changed
- Script-shutdown delivery uses one process-wide `register_shutdown_function()` that drains every
  live client instead of registering one closure (per instance) that pinned each client until
  process exit. Clients are weakly registered: an explicitly shut-down or garbage-collected client
  leaves the registry by itself. Behavior at exit is unchanged for clients held in scope.
- HTTP 3xx responses are now terminal: the batch fails immediately with a warn log instead of
  burning the full retry budget on an answer redirects cannot change. Redirects are never
  followed.
- `Utils::nowMs()` computes epoch milliseconds from split seconds/microseconds in exact integer
  arithmetic on 64-bit builds (the previous float multiply could wobble by ±1 ms). 32-bit builds
  keep best-effort float math, documented as such.
- Delivery failures are logged at warn level even when `debug` is off; dropped batches were
  previously invisible by default.
- Queue-overflow drops increment `lostTotal` and log at warn level.
