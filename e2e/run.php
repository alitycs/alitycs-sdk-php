<?php

declare(strict_types=1);

/**
 * E2E journey app, spawned as a subprocess by
 * `alitycs-autotests/tests/e2e/sdk/php.test.ts`. Emits an identify and a run-scoped
 * track event through the real Worker ingest endpoint; the test then reads them back
 * through the analytics API.
 */

require __DIR__ . '/../vendor/autoload.php';

use Alitycs\Client;

$required = ['ALITYCS_API_KEY', 'ALITYCS_ENDPOINT', 'ALITYCS_RUN_ID'];
foreach ($required as $name) {
    if ((string) getenv($name) === '') {
        fwrite(STDERR, "$name is required\n");
        exit(1);
    }
}

$apiKey = (string) getenv('ALITYCS_API_KEY');
$endpoint = (string) getenv('ALITYCS_ENDPOINT');
$runId = (string) getenv('ALITYCS_RUN_ID');
$phase = trim((string) getenv('ALITYCS_E2E_PHASE'));
$stateFile = trim((string) getenv('ALITYCS_STATE_FILE'));
if (!in_array($phase, ['', 'first', 'restart'], true)) {
    fwrite(STDERR, "ALITYCS_E2E_PHASE must be first, restart, or empty\n");
    exit(1);
}
if ($phase !== '' && $stateFile === '') {
    fwrite(STDERR, "ALITYCS_STATE_FILE is required for restart phases\n");
    exit(1);
}
if ($phase === 'first') {
    $endpoint = trim((string) getenv('ALITYCS_FAILURE_ENDPOINT'));
    if ($endpoint === '') {
        fwrite(STDERR, "ALITYCS_FAILURE_ENDPOINT is required for the first phase\n");
        exit(1);
    }
    if (!function_exists('pcntl_exec')) {
        fwrite(STDERR, "ext-pcntl is required for the restart scenario\n");
        exit(1);
    }
}

$eventName = "sdk_php_track_$runId";
$userId = "sdk-php-user-$runId";

$client = new Client($apiKey, [
    'endpoint' => $endpoint,
    'flushSize' => 10,
    'flushInterval' => 60.0,
    'maxRetries' => 0,
    'debug' => false,
    ...($stateFile !== '' ? ['persistencePath' => $stateFile] : []),
]);

if ($phase === 'first') {
    $client->setGlobalProperties([
        'test_run_id' => $runId,
        'sdk_package' => 'php',
        'scenario' => 'php-restart',
    ]);
    $client->track("sdk_php_restart_$runId");
    $client->flush();
    pcntl_exec('/usr/bin/env', ['true']);
    throw new RuntimeException('Unable to replace first-phase PHP process');
}
if ($phase === 'restart') {
    $client->flush();
    $client->shutdown();
    exit(0);
}

try {
    $client->setGlobalProperties([
        'test_run_id' => $runId,
        'sdk_package' => 'php',
        'scenario' => 'php-subprocess',
    ]);
    $client->identify($userId, ['runtime' => 'php']);
    $client->track($eventName, ['source' => 'php-sdk-e2e']);
    $client->track("sdk_php_request_a_$runId", userId: "sdk-php-request-a-$runId");
    $client->track("sdk_php_request_b_$runId", userId: "sdk-php-request-b-$runId");
    $client->flush();
} finally {
    // Delivers any remainder even if a send above threw — nothing is left queued.
    $client->shutdown();
}

echo "PHP SDK E2E emitted identify and $eventName\n";
