<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Spawned by {@see ForkSafetyTest}. The parent queues one event, forks, and both sides
 * deliver exactly their own event: the child's first SDK call detects the new process
 * id and hands the inherited queue back to the parent instead of double-sending it.
 */

$endpoint = $argv[1] ?? '';
if ($endpoint === '') {
    fwrite(STDERR, "usage: php fork-child.php <endpoint>\n");
    exit(1);
}

$client = new \Alitycs\Client('pk_fork_test', [
    'endpoint' => $endpoint,
    'flushSize' => 25,
    'flushInterval' => 0,
    'maxRetries' => 0,
]);
$client->setGlobalProperties(['scenario' => 'fork-safety']);
$client->track('fork_parent_event', ['role' => 'parent']);

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl extension required for this test\n");
    exit(2);
}

$childPid = pcntl_fork();
if ($childPid === -1) {
    fwrite(STDERR, "pcntl_fork failed\n");
    exit(3);
}

if ($childPid === 0) {
    // Child: no explicit reset — automatic detection on the next SDK call must drop
    // the inherited queue before this event is queued.
    $client->track('fork_child_event', ['role' => 'child']);
    $client->flush();
    exit(0);
}

// Parent: wait for the child so the recorded request order is deterministic.
pcntl_waitpid($childPid, $status);
$client->flush();
$client->shutdown();
exit(0);
