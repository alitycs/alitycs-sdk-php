<?php

declare(strict_types=1);

namespace Alitycs\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Fork safety with a real pcntl_fork: a child created after events are queued must not
 * inherit the queue (the parent delivers those) nor double-register shutdown delivery.
 * Proven in a child process, because the fork only exists there.
 */
final class ForkSafetyTest extends TestCase
{
    private const PROCESS_TIMEOUT_S = 15;

    public function testForkedChildDoesNotResendTheQueueItInherited(): void
    {
        if (!function_exists('pcntl_fork')) {
            // The guard path: without ext-pcntl the test is skipped, and the SDK's lazy
            // pid check needs no extension to keep forks safe anyway.
            self::markTestSkipped('ext-pcntl is not available');
        }

        $server = LocalIngestServer::start();

        try {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/fork-child.php', $server->endpoint()],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                __DIR__
            );
            self::assertIsResource($process);

            $output = '';
            $deadline = microtime(true) + self::PROCESS_TIMEOUT_S;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(20_000);
            }
            while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
                $output .= $chunk;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
            $this->assertSame(0, $exitCode, "child exited $exitCode: $output");

            $requests = $server->requests();
            $this->assertCount(2, $requests, 'one batch per process — no inherited-queue resend');

            // The parent waits for the child before flushing, so the child's batch
            // arrives first.
            $childRequest = $requests[0];
            $parentRequest = $requests[1];

            $childEvents = $childRequest['body']['events'];
            $parentEvents = $parentRequest['body']['events'];
            $this->assertSame(['fork_child_event'], array_column($childEvents, 'event'));
            $this->assertSame(['fork_parent_event'], array_column($parentEvents, 'event'));

            // The inherited queue was dropped, not moved: the parent event is sent by
            // the parent exactly once across every recorded request.
            $allEventNames = array_column([...$childEvents, ...$parentEvents], 'event');
            $this->assertSame(1, count(array_keys($allEventNames, 'fork_parent_event')));

            // Both processes share the global properties but no longer the session id.
            $this->assertSame('fork-safety', $childEvents[0]['properties']['scenario']);
            $this->assertNotSame(
                $childEvents[0]['sessionId'],
                $parentEvents[0]['sessionId'],
                'the child rotates onto its own session'
            );
        } finally {
            $server->stop();
        }
    }
}
