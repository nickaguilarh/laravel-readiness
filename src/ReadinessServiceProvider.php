<?php

declare(strict_types=1);

namespace Readiness;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Readiness\Checks\DatabaseCheck;
use Readiness\Checks\HorizonCheck;
use Readiness\Checks\RedisCheck;
use Readiness\Checks\StaleDrainCheck;
use Readiness\Console\DrainCommand;
use Readiness\Console\DrainStatusCommand;
use Readiness\Console\UndrainCommand;
use Readiness\Http\ReadinessController;
use Readiness\Http\RejectWhileDraining;
use Readiness\Support\Access;

final class ReadinessServiceProvider extends ServiceProvider
{
    private const MAINTENANCE_DISABLED = 'Illuminate\Foundation\Events\MaintenanceModeDisabled';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/readiness.php', 'readiness');

        $this->app->singleton(Readiness::class, fn (Container $app) => new Readiness($app, $app->make(Repository::class)));

        $this->app->singleton(Access::class, function (Container $app) {
            $config = $app->make(Repository::class);

            return new Access(
                array_values((array) $config->get('readiness.access.allowed_ips', [])),
                $config->get('readiness.access.token'),
                (string) $config->get('readiness.access.header', 'X-Readiness-Token'),
            );
        });

        $this->app->bind(DatabaseCheck::class, function (Container $app) {
            $config = $app->make(Repository::class);

            return new DatabaseCheck(
                $app->make(DatabaseManager::class),
                $config->get('readiness.database.connection'),
                (float) $config->get('readiness.database.timeout', 1.0),
            );
        });

        $this->app->bind(RedisCheck::class, function (Container $app) {
            $config = $app->make(Repository::class);

            return new RedisCheck(
                $app->make(RedisFactory::class),
                $config->get('readiness.redis.connection'),
                (float) $config->get('readiness.redis.timeout', 1.0),
            );
        });

        $this->app->bind(HorizonCheck::class, fn (Container $app) => new HorizonCheck(
            $app,
            (float) $app->make(Repository::class)->get('readiness.horizon.timeout', 1.0),
        ));

        $this->app->bind(StaleDrainCheck::class, fn (Container $app) => new StaleDrainCheck(
            (int) $app->make(Repository::class)->get('readiness.drain.stale_after', 1800),
        ));
    }

    public function boot(): void
    {
        $config = $this->app->make(Repository::class);

        Drain::configure(
            $this->markerPath($config->get('readiness.drain.path')),
            (int) $config->get('readiness.drain.max_ttl', Drain::DEFAULT_MAX_TTL),
        );

        $this->app->make(Router::class)->aliasMiddleware('readiness.drain', RejectWhileDraining::class);

        if ($config->get('readiness.route.enabled')) {
            $this->registerRoute($config);
        }

        $this->clearDrainWhenMaintenanceEnds($config);

        if ($this->app->runningInConsole()) {
            $this->commands([DrainCommand::class, UndrainCommand::class, DrainStatusCommand::class]);

            $this->publishes([
                __DIR__.'/../config/readiness.php' => $this->app->configPath('readiness.php'),
            ], 'readiness-config');
        }
    }

    private function markerPath(mixed $path): ?string
    {
        return is_string($path) && $path !== '' ? $path : null;
    }

    private function registerRoute(Repository $config): void
    {
        $this->app->make(Router::class)
            ->get((string) $config->get('readiness.route.path', 'readyz'), ReadinessController::class)
            ->middleware((array) $config->get('readiness.route.middleware', []))
            ->name('readiness');
    }

    /**
     * `artisan up` ends the deploy window, so a drain left from before it must
     * not outlive it. The event exists from Laravel 8.83.
     */
    private function clearDrainWhenMaintenanceEnds(Repository $config): void
    {
        if (! $config->get('readiness.drain.clear_on_up', true) || ! class_exists(self::MAINTENANCE_DISABLED)) {
            return;
        }

        $this->app->make('events')->listen(self::MAINTENANCE_DISABLED, static function (): void {
            if (Drain::path() !== null) {
                Drain::stop();
            }
        });
    }
}
