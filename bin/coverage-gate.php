<?php

declare(strict_types=1);

/**
 * The committed coverage gate: runs the full PHPUnit suite with a Clover report and
 * fails unless lines >= 90% and methods >= 85% — the same bar every other Alitycs SDK
 * is held to. PHPUnit has no native threshold enforcement, so this script is what makes
 * `composer run test:coverage` (and the conformance harness's coverage row) exit
 * non-zero below the gate.
 *
 * Usage: php bin/coverage-gate.php [--min-lines=0.90] [--min-methods=0.85]
 */

const MIN_LINES = 0.90;
const MIN_METHODS = 0.85;

$root = dirname(__DIR__);
$clover = $root . '/coverage.xml';

passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/vendor/bin/phpunit')
    . ' --coverage-clover=' . escapeshellarg($clover),
    $exitCode
);

if ($exitCode !== 0) {
    fwrite(STDERR, "Coverage gate: the test suite failed (exit $exitCode).\n");
    exit($exitCode);
}

if (!is_file($clover)) {
    fwrite(STDERR, "Coverage gate: Clover report was not written to $clover.\n");
    exit(1);
}

$coverage = simplexml_load_file($clover);
$metrics = $coverage->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "Coverage gate: Clover report has no project metrics.\n");
    exit(1);
}

$statements = (int) $metrics['statements'];
$coveredStatements = (int) $metrics['coveredstatements'];
$methods = (int) $metrics['methods'];
$coveredMethods = (int) $metrics['coveredmethods'];

if ($statements === 0 || $methods === 0) {
    fwrite(STDERR, "Coverage gate: Clover report measured nothing — is the pcov driver enabled?\n");
    exit(1);
}

$lineRatio = $coveredStatements / $statements;
$methodRatio = $coveredMethods / $methods;

printf(
    "Coverage: %.2f%% lines (%d/%d), %.2f%% methods (%d/%d)\n",
    $lineRatio * 100,
    $coveredStatements,
    $statements,
    $methodRatio * 100,
    $coveredMethods,
    $methods
);

$failures = [];
if ($lineRatio < MIN_LINES) {
    $failures[] = sprintf('lines %.2f%% is below the %.0f%% gate', $lineRatio * 100, MIN_LINES * 100);
}
if ($methodRatio < MIN_METHODS) {
    $failures[] = sprintf('methods %.2f%% is below the %.0f%% gate', $methodRatio * 100, MIN_METHODS * 100);
}

if ($failures !== []) {
    fwrite(STDERR, "Coverage gate failed: " . implode('; ', $failures) . ".\n");
    exit(1);
}

echo "Coverage gate passed.\n";
