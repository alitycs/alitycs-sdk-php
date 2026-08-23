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

$eventName = "sdk_php_track_$runId";
$userId = "sdk-php-user-$runId";

$client = new Client($apiKey, [
    'endpoint' => $endpoint,
    'flushSize' => 10,
    'flushInterval' => 60.0,
    'maxRetries' => 0,
    'debug' => false,
]);

try {
    $client->setGlobalProperties([
        'test_run_id' => $runId,
        'sdk_package' => 'php',
        'scenario' => 'php-subprocess',
    ]);
    $client->identify($userId, ['runtime' => 'php']);
    $client->track($eventName, ['source' => 'php-sdk-e2e']);
    $client->flush();
} finally {
    // Delivers any remainder even if a send above threw — nothing is left queued.
    $client->shutdown();
}

echo "PHP SDK E2E emitted identify and $eventName\n";
