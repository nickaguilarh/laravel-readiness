<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Tests\Feature;

use Illuminate\Contracts\Redis\Factory;
use NickAguilarH\Readiness\Checks;
use NickAguilarH\Readiness\Drain;
use NickAguilarH\Readiness\Readiness;
use NickAguilarH\Readiness\Support\Access;
use NickAguilarH\Readiness\Support\Probe;
use NickAguilarH\Readiness\Tests\TestCase;
use RuntimeException;

final class EndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('readiness.route.enabled', true);
        $app['config']->set('readiness.access.allowed_ips', ['127.0.0.1']);
        $app['config']->set('readiness.access.token', 'expected-token');
        $app['config']->set('readiness.checks', [
            Checks\DrainingCheck::class,
            Checks\MaintenanceCheck::class,
            Checks\DatabaseCheck::class,
            Checks\StaleDrainCheck::class,
        ]);
    }

    public function test_it_reports_ready_from_an_allowed_address(): void
    {
        $this->getJson('/readyz', ['REMOTE_ADDR' => '127.0.0.1'])
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.draining.status', 'ok')
            ->assertJsonStructure(['status', 'timestamp', 'duration_ms', 'checks'])
            ->assertHeader('Cache-Control');
    }

    public function test_the_answer_is_never_cached(): void
    {
        $this->assertStringContainsString('no-store', $this->get('/readyz')->headers->get('Cache-Control'));
    }

    public function test_it_does_not_expose_the_hostname(): void
    {
        $this->assertStringNotContainsString(gethostname(), $this->get('/readyz')->getContent());
    }

    public function test_it_is_refused_from_an_address_outside_the_allow_list(): void
    {
        $this->app['config']->set('readiness.access.allowed_ips', ['10.0.0.1']);
        $this->app->forgetInstance(Access::class);

        $this->get('/readyz')->assertForbidden();
    }

    public function test_the_token_gets_in_from_any_address(): void
    {
        $this->app['config']->set('readiness.access.allowed_ips', ['10.0.0.1']);
        $this->app->forgetInstance(Access::class);

        $this->get('/readyz', ['X-Readiness-Token' => 'wrong'])->assertForbidden();
        $this->get('/readyz', ['X-Readiness-Token' => 'expected-token'])->assertOk();
    }

    public function test_a_failing_check_answers_503(): void
    {
        $this->app['config']->set('readiness.database.connection', 'missing');

        $this->getJson('/readyz')
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.database.status', 'failed')
            ->assertJsonPath('checks.database.message', 'check failed');
    }

    public function test_error_messages_are_exposed_only_when_enabled(): void
    {
        $this->app['config']->set('readiness.database.connection', 'missing');
        $this->app['config']->set('readiness.expose_errors', true);

        $this->assertNotSame('check failed', $this->getJson('/readyz')->json('checks.database.message'));
    }

    public function test_a_drain_answers_503_without_running_the_other_checks(): void
    {
        $this->app['config']->set('readiness.database.connection', 'missing');
        Drain::start(120);

        $response = $this->getJson('/readyz')
            ->assertStatus(503)
            ->assertJsonPath('status', 'draining')
            ->assertJsonPath('checks.draining.status', 'failed');

        $this->assertArrayNotHasKey('database', $response->json('checks'));

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_without_short_circuit_every_check_still_runs(): void
    {
        $this->app['config']->set('readiness.drain.short_circuit', false);
        Drain::start(120);

        $this->getJson('/readyz')
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.database.status', 'ok');
    }

    public function test_the_route_is_not_registered_unless_enabled(): void
    {
        $this->assertTrue($this->app['router']->has('readiness'));
    }

    public function test_the_service_runs_standalone_and_resolves_custom_checks(): void
    {
        $custom = new class implements Checks\Check
        {
            public function name(): string
            {
                return 'custom';
            }

            public function run(Probe $probe): array
            {
                return $probe->run(1.0, function (): void {
                    throw new RuntimeException('nope');
                });
            }
        };
        $this->app->instance($custom::class, $custom);
        $this->app['config']->set('readiness.checks', [$custom::class, '', 42, \stdClass::class]);

        $report = $this->app->make(Readiness::class)->run();

        $this->assertSame(['custom'], array_keys($report->checks()));
        $this->assertSame('not_ready', $report->status());
    }

    public function test_the_redis_check_resolves_through_the_container(): void
    {
        $factory = $this->createStub(Factory::class);
        $this->app->instance(Factory::class, $factory);

        $this->assertInstanceOf(Checks\RedisCheck::class, $this->app->make(Checks\RedisCheck::class));
        $this->assertInstanceOf(Checks\HorizonCheck::class, $this->app->make(Checks\HorizonCheck::class));
        $this->assertInstanceOf(Checks\StaleDrainCheck::class, $this->app->make(Checks\StaleDrainCheck::class));
    }
}
