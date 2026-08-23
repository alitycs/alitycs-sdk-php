<?php

declare(strict_types=1);

namespace Alitycs\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The shutdown contract: a script that ends without flush()/shutdown() still delivers
 * every enqueued event exactly once. Proven with a real child process, because the hook
 * only runs when PHP tears the process down.
 */
final class ImplicitShutdownDeliveryTest extends TestCase
{
    private const PROCESS_TIMEOUT_S = 15;

    public function testScriptExitWithoutFlushStillDeliversQueuedEvents(): void
    {
        $server = LocalIngestServer::start();

        try {
            $eventName = 'implicit_shutdown_' . bin2hex(random_bytes(4));

            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/shutdown-child.php', $server->endpoint(), $eventName],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                __DIR__
            );
            self::assertIsResource($process);

            $stdout = '';
            $deadline = microtime(true) + self::PROCESS_TIMEOUT_S;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(20_000);
            }
            while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
                $stdout .= $chunk;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
            $this->assertSame(0, $exitCode, "child exited $exitCode: $stdout");

            $requests = $server->requests();
            $this->assertCount(1, $requests, 'the queued event is delivered at exit');

            $events = $requests[0]['body']['events'] ?? [];
            $this->assertCount(1, $events);
            $this->assertSame($eventName, $events[0]['event']);
            $this->assertSame('implicit-shutdown', $events[0]['properties']['scenario']);
        } finally {
            $server->stop();
        }
    }
}
