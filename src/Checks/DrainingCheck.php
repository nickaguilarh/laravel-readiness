<?php

declare(strict_types=1);

namespace Readiness\Checks;

use Readiness\Drain;
use Readiness\Support\Probe;

/**
 * Fails while the instance is draining, which is what turns the readiness
 * answer into a 503 and pulls the instance out of the load balancer.
 */
final class DrainingCheck implements Check
{
    public function name(): string
    {
        return 'draining';
    }

    public function run(Probe $probe): array
    {
        $status = Drain::status();

        if ($status === null) {
            return ['status' => Probe::STATUS_OK, 'duration_ms' => 0.0];
        }

        return [
            'status' => Probe::STATUS_FAILED,
            'duration_ms' => 0.0,
            'message' => 'draining',
            'remaining_seconds' => $status['remaining'],
        ];
    }
}
