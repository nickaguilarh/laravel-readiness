<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Tests\Feature;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\DatabaseManager;
use NickAguilarH\Readiness\Checks\DatabaseCheck;
use NickAguilarH\Readiness\Checks\DrainingCheck;
use NickAguilarH\Readiness\Checks\HorizonCheck;
use NickAguilarH\Readiness\Checks\MaintenanceCheck;
use NickAguilarH\Readiness\Checks\RedisCheck;
use NickAguilarH\Readiness\Checks\StaleDrainCheck;
use NickAguilarH\Readiness\Drain;
use NickAguilarH\Readiness\Support\Probe;
use NickAguilarH\Readiness\Tests\TestCase;
use RuntimeException;

final class ChecksTest extends TestCase
{
    public function test_the_database_check_passes_against_a_live_connection(): void
    {
        $result = (new DatabaseCheck($this->app->make(DatabaseManager::class)))->run(new Probe(1.0));

        $this->assertSame('ok', $result['status']);
        $this->assertSame('sqlite', $result['driver']);
        $this->assertSame('database', (new DatabaseCheck($this->app->make(DatabaseManager::class)))->name());
    }

    public function test_the_database_check_fails_on_an_unknown_connection(): void
    {
        $result = (new DatabaseCheck($this->app->make(DatabaseManager::class), 'missing'))->run(new Probe(1.0));

        $this->assertSame('failed', $result['status']);
    }

    public function test_the_database_check_bounds_mysql_queries_on_the_server(): void
    {
        $connection = new class
        {
            public ?string $query = null;

            public function getDriverName(): string
            {
                return 'mysql';
            }

            public function select(string $query): array
            {
                $this->query = $query;

                return [];
            }
        };
        $database = $this->createStub(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);

        $result = (new DatabaseCheck($database, null, 0.75))->run(new Probe(5.0));

        $this->assertSame('ok', $result['status']);
        $this->assertMatchesRegularExpression('/MAX_EXECUTION_TIME\((\d+)\)/', $connection->query);
        preg_match('/MAX_EXECUTION_TIME\((\d+)\)/', $connection->query, $match);
        $this->assertLessThanOrEqual(750, (int) $match[1]);
    }

    public function test_the_redis_check_pings_the_connection(): void
    {
        $connection = new class
        {
            public int $pings = 0;

            public function ping(): void
            {
                $this->pings++;
            }
        };
        $factory = $this->createStub(Factory::class);
        $factory->method('connection')->willReturn($connection);

        $check = new RedisCheck($factory);

        $this->assertSame('redis', $check->name());
        $this->assertSame('ok', $check->run(new Probe(1.0))['status']);
        $this->assertSame(1, $connection->pings);
    }

    public function test_the_redis_check_fails_when_the_ping_throws(): void
    {
        $factory = $this->createStub(Factory::class);
        $factory->method('connection')->willThrowException(new RuntimeException('refused'));

        $this->assertSame('failed', (new RedisCheck($factory))->run(new Probe(1.0))['status']);
    }

    public function test_the_horizon_check_is_skipped_when_horizon_is_not_installed(): void
    {
        $check = new HorizonCheck($this->app);

        $this->assertSame('horizon', $check->name());
        $this->assertSame('skipped', $check->run(new Probe(1.0))['status']);
    }

    public function test_the_horizon_check_needs_a_master_supervisor(): void
    {
        $result = (new HorizonCheck($this->app, 1.0, fn () => []))->run(new Probe(1.0));

        $this->assertSame('failed', $result['status']);
    }

    public function test_the_horizon_check_fails_when_a_master_is_paused(): void
    {
        $masters = [(object) ['status' => 'running'], (object) ['status' => 'paused']];

        $this->assertSame('failed', (new HorizonCheck($this->app, 1.0, fn () => $masters))->run(new Probe(1.0))['status']);
    }

    public function test_the_horizon_check_passes_with_running_masters(): void
    {
        $generator = (function () {
            yield (object) ['status' => 'running'];
        });

        $result = (new HorizonCheck($this->app, 1.0, $generator))->run(new Probe(1.0));

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['masters']);
    }

    public function test_the_draining_check_follows_the_marker(): void
    {
        $check = new DrainingCheck;

        $this->assertSame('draining', $check->name());
        $this->assertSame('ok', $check->run(new Probe(1.0))['status']);

        Drain::start(60);
        $result = $check->run(new Probe(1.0));

        $this->assertSame('failed', $result['status']);
        $this->assertSame('draining', $result['message']);
        $this->assertGreaterThan(0, $result['remaining_seconds']);
    }

    public function test_the_stale_drain_check_warns_about_a_forgotten_drain(): void
    {
        $check = new StaleDrainCheck(1800);

        $this->assertSame('stale_drain', $check->name());
        $this->assertSame('ok', $check->run(new Probe(1.0))['status']);

        Drain::start(3600, now: time() - 100);
        $this->assertSame('ok', $check->run(new Probe(1.0))['status']);

        Drain::start(3600, now: time() - 2000);
        $result = $check->run(new Probe(1.0));

        $this->assertSame('warning', $result['status']);
        $this->assertGreaterThan(1800, $result['age_seconds']);
    }

    public function test_the_stale_drain_check_is_ok_when_the_age_is_unknown(): void
    {
        file_put_contents($this->marker.'/drain', 'garbage');
        touch($this->marker.'/drain', time() + 60);

        $this->assertSame('ok', (new StaleDrainCheck(1))->run(new Probe(1.0))['status']);
    }

    public function test_the_maintenance_check_follows_maintenance_mode(): void
    {
        $check = new MaintenanceCheck($this->app);
        $down = $this->app->storagePath().'/framework/down';

        $this->assertSame('maintenance', $check->name());
        $this->assertSame('ok', $check->run(new Probe(1.0))['status']);

        file_put_contents($down, '{}');

        try {
            $result = $check->run(new Probe(1.0));
        } finally {
            unlink($down);
        }

        $this->assertSame('failed', $result['status']);
    }
}
