<?php

declare(strict_types=1);

namespace Readiness\Checks;

use Readiness\Drain;
use Readiness\Support\Probe;

/**
 * Warns when a drain has been active for longer than expected, a sign that
 * someone forgot to undrain. It never decides the verdict, the draining check
 * already does.
 */
final class StaleDrainCheck implements Check
{
    public function __construct(private int $staleAfter = 1800) {}

    public function name(): string
    {
        return 'stale_drain';
    }

    public function run(Probe $probe): array
    {
        $status = Drain::status();

        if ($status === null) {
            return ['status' => Probe::STATUS_OK, 'duration_ms' => 0.0];
        }

        if ($status['age'] !== null && $status['age'] > $this->staleAfter) {
            return [
                'status' => Probe::STATUS_WARNING,
                'duration_ms' => 0.0,
                'message' => 'drain has been active longer than expected',
                'age_seconds' => $status['age'],
            ];
        }

        return ['status' => Probe::STATUS_OK, 'duration_ms' => 0.0];
    }
}
