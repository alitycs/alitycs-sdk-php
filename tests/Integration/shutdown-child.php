<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Spawned by {@see ImplicitShutdownDeliveryTest}. Enqueues one event and exits without
 * flushing — only `register_shutdown_function()` stands between the event and oblivion.
 */

$endpoint = $argv[1] ?? '';
$eventName = $argv[2] ?? '';
if ($endpoint === '' || $eventName === '') {
    fwrite(STDERR, "usage: php shutdown-child.php <endpoint> <event-name>\n");
    exit(1);
}

$client = new \Alitycs\Client('pk_shutdown_test', [
    'endpoint' => $endpoint,
    'flushSize' => 25,
    'flushInterval' => 0,
    'maxRetries' => 0,
]);
$client->setGlobalProperties(['scenario' => 'implicit-shutdown']);
$client->track($eventName, ['delivery' => 'register_shutdown_function']);

// Deliberately no flush() and no shutdown() — script exit must deliver the event.
