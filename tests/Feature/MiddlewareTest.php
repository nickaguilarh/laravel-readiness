<?php

declare(strict_types=1);

namespace Readiness\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Readiness\Drain;
use Readiness\Tests\TestCase;

final class MiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/own-readyz', fn () => response()->json(['status' => 'ready']))->middleware('readiness.drain');
        Route::get('/direct-readyz', fn () => Drain::isDraining() ? Drain::unavailable() : response()->json(['status' => 'ready']));
    }

    public function test_the_alias_passes_requests_through_when_not_draining(): void
    {
        $this->getJson('/own-readyz')->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_the_alias_answers_503_while_draining(): void
    {
        Drain::start(120, 'internal reason');

        $this->getJson('/own-readyz')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'draining'])
            ->assertHeader('Retry-After');
    }

    public function test_the_route_can_answer_for_itself_without_middleware(): void
    {
        $this->getJson('/direct-readyz')->assertOk();

        Drain::start(120);

        $this->getJson('/direct-readyz')->assertStatus(503);
    }

    public function test_the_alias_registers_after_the_drain_ends(): void
    {
        Drain::start(120);
        Drain::stop();

        $this->getJson('/own-readyz')->assertOk();
    }
}
