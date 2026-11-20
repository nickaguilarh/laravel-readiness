<?php

declare(strict_types=1);

namespace Readiness\Checks;

use Closure;
use Illuminate\Contracts\Container\Container;
use Readiness\Support\Probe;
use RuntimeException;

/**
 * Mirrors `php artisan horizon:status`: at least one master supervisor has to
 * have checked in and none of them may be paused.
 *
 * Skipped when laravel/horizon is not installed.
 */
final class HorizonCheck implements Check
{
    private const REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

    /**
     * @param  Closure(): iterable<object>|null  $masters  Overrides how the masters are read.
     */
    public function __construct(
        private Container $container,
        private float $timeout = 1.0,
        private ?Closure $masters = null,
    ) {}

    public function name(): string
    {
        return 'horizon';
    }

    public function run(Probe $probe): array
    {
        if ($this->masters === null && ! interface_exists(self::REPOSITORY)) {
            return Probe::skipped('laravel/horizon is not installed');
        }

        return $probe->run($this->timeout, function (): array {
            $masters = $this->masters !== null
                ? ($this->masters)()
                : $this->container->make(self::REPOSITORY)->all();

            $masters = is_array($masters) ? $masters : iterator_to_array($masters, false);

            if ($masters === []) {
                throw new RuntimeException('Horizon is inactive.');
            }

            foreach ($masters as $master) {
                if (($master->status ?? null) === 'paused') {
                    throw new RuntimeException('Horizon is paused.');
                }
            }

            return ['masters' => count($masters)];
        });
    }
}
