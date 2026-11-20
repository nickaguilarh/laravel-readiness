<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Checks;

use NickAguilarH\Readiness\Support\Probe;

interface Check
{
    /**
     * The key the result is reported under.
     */
    public function name(): string;

    /**
     * Run the check through the probe, so it shares the overall budget.
     *
     * @return array<string, mixed>
     */
    public function run(Probe $probe): array;
}
