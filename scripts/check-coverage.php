<?php

declare(strict_types=1);

/**
 * Fails when line coverage in a Clover report is below a threshold.
 *
 * Usage: php scripts/check-coverage.php build/clover.xml 95
 */
$file = $argv[1] ?? '';
$minimum = (float) ($argv[2] ?? 0);

if (! is_file($file)) {
    fwrite(STDERR, "Clover report not found: {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
$metrics = $xml === false ? null : $xml->project->metrics;

if ($metrics === null) {
    fwrite(STDERR, "The report has no project metrics.\n");
    exit(2);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements === 0 ? 100.0 : $covered / $statements * 100;

printf("Line coverage: %.2f%% (%d/%d), minimum %.2f%%\n", $percent, $covered, $statements, $minimum);

exit($percent + 1e-9 >= $minimum ? 0 : 1);
