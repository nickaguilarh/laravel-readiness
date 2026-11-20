<?php

declare(strict_types=1);

namespace Readiness;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Readiness\Checks\Check;
use Readiness\Checks\DrainingCheck;
use Readiness\Support\Probe;

/**
 * Builds the configured checks and runs them under one budget.
 */
final class Readiness
{
    public function __construct(
        private Container $container,
        private Repository $config,
    ) {}

    public function run(): Report
    {
        $probe = new Probe(
            (float) $this->config->get('readiness.budget', 3.0),
            (bool) $this->config->get('readiness.expose_errors', false),
        );

        // A drained instance has already decided it is not ready. Answering
        // without touching the database or Redis keeps the probe cheap
        // exactly when the instance is being taken out of rotation.
        if ($this->config->get('readiness.drain.short_circuit', true) && Drain::isDraining()) {
            $draining = new DrainingCheck;

            return new Report([$draining->name() => $draining->run($probe)], $probe->elapsedMs(), true);
        }

        $results = [];

        foreach ($this->checks() as $check) {
            $results[$check->name()] = $check->run($probe);
        }

        return new Report($results, $probe->elapsedMs());
    }

    /**
     * @return list<Check>
     */
    private function checks(): array
    {
        $checks = [];

        foreach ((array) $this->config->get('readiness.checks', []) as $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            $check = $this->container->make($class);

            if ($check instanceof Check) {
                $checks[] = $check;
            }
        }

        return $checks;
    }
}
