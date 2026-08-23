<?php

declare(strict_types=1);

namespace Alitycs\Tests\Integration;

/**
 * A real HTTP listener for integration tests: `php -S` in a subprocess, bound to a
 * random port. The SDK under test talks to it over genuine curl traffic; requests are
 * recorded by the router script and read back here after the synchronous client has
 * finished — no mocked handlers anywhere.
 */
final class LocalIngestServer
{
    private const READY_TIMEOUT_MS = 10_000;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private readonly string $endpoint;

    private readonly string $requestsFile;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct($process, array $pipes, string $requestsFile, string $endpoint)
    {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->requestsFile = $requestsFile;
        $this->endpoint = $endpoint;
    }

    /**
     * @param string $statusPlan comma-separated statuses per request sequence ("500,202"
     *                           fails the first request then accepts the rest); empty means always 202
     */
    public static function start(string $statusPlan = ''): self
    {
        $requestsFile = (string) tempnam(sys_get_temp_dir(), 'alitycs-ingest-');

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:0', __DIR__ . '/ingest-router.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__,
            [
                'STATUS_PLAN' => $statusPlan,
                'REQUESTS_FILE' => $requestsFile,
            ]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start the local ingest server');
        }

        // The dev server announces its bound port on stderr once it is accepting.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffer = '';
        $deadline = microtime(true) + self::READY_TIMEOUT_MS / 1000;
        while (microtime(true) < $deadline) {
            foreach ([1, 2] as $pipe) {
                $chunk = fread($pipes[$pipe], 8192);
                if (is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                }
            }

            if (preg_match('#http://127\.0\.0\.1:(\d+)#', $buffer, $matches) === 1) {
                return new self($process, $pipes, $requestsFile, "http://127.0.0.1:{$matches[1]}/events");
            }

            usleep(10_000);
        }

        self::terminate($process, $pipes);

        throw new \RuntimeException('Local ingest server never announced its port: ' . substr($buffer, 0, 500));
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Every recorded request in arrival order.
     *
     * @return list<array{sequence: int, method: string, authorization: ?string, contentType: ?string, body: mixed, status: int}>
     */
    public function requests(): array
    {
        if (!is_file($this->requestsFile)) {
            return [];
        }

        $requests = [];
        foreach (file($this->requestsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $requests[] = $decoded;
            }
        }

        return $requests;
    }

    public function stop(): void
    {
        self::terminate($this->process, $this->pipes);

        if (is_file($this->requestsFile)) {
            @unlink($this->requestsFile);
        }
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private static function terminate($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
