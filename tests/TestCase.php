<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Tests;

use NickAguilarH\Readiness\Drain;
use NickAguilarH\Readiness\ReadinessServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $marker;

    protected function getPackageProviders($app): array
    {
        return [ReadinessServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->marker = sys_get_temp_dir().'/readiness-test-'.bin2hex(random_bytes(6));
        mkdir($this->marker, 0700);

        $app['config']->set('readiness.drain.path', $this->marker.'/drain');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function tearDown(): void
    {
        Drain::configure($this->marker.'/drain');
        Drain::stop();
        Drain::configure(null);

        foreach (glob($this->marker.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }

        @rmdir($this->marker);

        parent::tearDown();
    }
}
