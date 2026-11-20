<?php

declare(strict_types=1);

namespace Readiness\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Readiness\Drain;
use Readiness\Tests\TestCase;

final class ClearOnUpDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('readiness.drain.clear_on_up', false);
    }

    public function test_artisan_up_keeps_the_drain_when_clearing_is_disabled(): void
    {
        Drain::start(60);

        Artisan::call('down');
        Artisan::call('up');

        $this->assertTrue(Drain::isDraining());
    }
}
