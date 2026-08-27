<?php

declare(strict_types=1);

/**
 * Router for the `php -S` listener behind {@see LocalIngestServer}: records every
 * request it receives as one JSON line in REQUESTS_FILE and answers with the status
 * named in STATUS_PLAN ("500,202" fails the first request then accepts the rest; the
 * final status repeats for any further requests; empty means always 202).
 *
 * RETRY_AFTER_PLAN ("2,,date") optionally sends a matching-position `Retry-After`
 * header: a number is sent verbatim as delta-seconds; the token "date" sends an
 * HTTP-date three seconds in the future; empty sends nothing.
 */

// getenv(), not $_SERVER: the built-in server rebuilds $_SERVER per request and drops
// custom environment variables, so config passed through proc_open is only visible here.
$planParameter = trim((string) getenv('STATUS_PLAN'));
$statusPlan = $planParameter === ''
    ? [202]
    : array_map(intval(...), explode(',', $planParameter));

$retryAfterParameter = trim((string) getenv('RETRY_AFTER_PLAN'));
$retryAfterPlan = $retryAfterParameter === ''
    ? []
    : explode(',', $retryAfterParameter);

$requestsFile = (string) getenv('REQUESTS_FILE');

$previous = 0;
if (is_file($requestsFile)) {
    foreach (file($requestsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $previous++;
    }
}

$sequence = $previous + 1;
$status = $statusPlan[min(count($statusPlan) - 1, $sequence - 1)];

$record = [
    'sequence' => $sequence,
    'method' => $_SERVER['REQUEST_METHOD'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
    'body' => json_decode((string) file_get_contents('php://input'), true),
    'status' => $status,
];

file_put_contents($requestsFile, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

http_response_code($status);
header('Content-Type: application/json');
$retryAfter = $retryAfterPlan[min(count($retryAfterPlan) - 1, $sequence - 1)] ?? '';
if ($retryAfter !== '') {
    header('Retry-After: ' . ($retryAfter === 'date'
        ? gmdate('D, d M Y H:i:s \G\M\T', time() + 3)
        : $retryAfter));
}
echo json_encode($status < 300 ? ['accepted' => true] : ['error' => 'injected_status_' . $status]);
