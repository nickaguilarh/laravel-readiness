<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Checks;

use Illuminate\Contracts\Foundation\Application;
use NickAguilarH\Readiness\Support\Probe;

/**
 * Fails while `artisan down` is active. The maintenance middleware already
 * answers 503 on its own, this puts the reason in the report.
 */
final class MaintenanceCheck implements Check
{
    public function __construct(private Application $app) {}

    public function name(): string
    {
        return 'maintenance';
    }

    public function run(Probe $probe): array
    {
        if ($this->app->isDownForMaintenance()) {
            return [
                'status' => Probe::STATUS_FAILED,
                'duration_ms' => 0.0,
                'message' => 'maintenance mode is active',
            ];
        }

        return ['status' => Probe::STATUS_OK, 'duration_ms' => 0.0];
    }
}
