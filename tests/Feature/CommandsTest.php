<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use NickAguilarH\Readiness\Drain;
use NickAguilarH\Readiness\Tests\TestCase;

final class CommandsTest extends TestCase
{
    /**
     * @return array{int, string}
     */
    private function runArtisan(string $command): array
    {
        $code = Artisan::call($command);

        return [$code, Artisan::output()];
    }

    public function test_drain_marks_the_instance_with_the_default_ttl(): void
    {
        [$code, $output] = $this->runArtisan('drain');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Draining until', $output);
        $this->assertTrue(Drain::isDraining());
        $this->assertLessThanOrEqual(900, Drain::status()['remaining']);
    }

    public function test_drain_accepts_a_ttl_and_a_reason(): void
    {
        [$code] = $this->runArtisan('drain --ttl=120 --reason=migrating');

        $this->assertSame(0, $code);
        $this->assertSame('migrating', Drain::status()['reason']);
        $this->assertLessThanOrEqual(120, Drain::status()['remaining']);
    }

    public function test_drain_can_wait_for_the_load_balancer(): void
    {
        $start = microtime(true);

        [$code, $output] = $this->runArtisan('drain --ttl=30 --wait=1');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Waiting 1 seconds', $output);
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $start);
    }

    public function test_drain_rejects_non_numeric_options(): void
    {
        $this->assertSame(1, $this->runArtisan('drain --ttl=abc')[0]);
        $this->assertSame(1, $this->runArtisan('drain --wait=-5')[0]);
        $this->assertSame(1, $this->runArtisan('drain --wait=1.5')[0]);

        $this->assertFalse(Drain::isDraining());
    }

    public function test_drain_rejects_a_wait_longer_than_the_ttl(): void
    {
        [$code, $output] = $this->runArtisan('drain --ttl=10 --wait=20');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('cannot be longer', $output);
        $this->assertFalse(Drain::isDraining());
    }

    public function test_drain_rejects_a_ttl_above_the_maximum(): void
    {
        $this->assertSame(1, $this->runArtisan('drain --ttl=999999')[0]);
    }

    public function test_drain_reports_an_unwritable_marker(): void
    {
        Drain::configure($this->marker.'/missing/drain');

        [$code, $output] = $this->runArtisan('drain');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not writable', $output);
    }

    public function test_undrain_clears_the_marker(): void
    {
        Drain::start(60);

        [$code, $output] = $this->runArtisan('undrain');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Drain cleared.', $output);
        $this->assertFalse(Drain::isDraining());
    }

    public function test_undrain_is_harmless_when_not_draining(): void
    {
        [$code, $output] = $this->runArtisan('undrain');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('was not draining', $output);
    }

    public function test_status_describes_the_drain(): void
    {
        $this->assertStringContainsString('Not draining.', $this->runArtisan('drain:status')[1]);

        Drain::start(60, 'deploy');

        $output = $this->runArtisan('drain:status')[1];

        $this->assertStringContainsString('Draining, ', $output);
        $this->assertStringContainsString('Reason: deploy', $output);
    }

    public function test_status_without_a_reason_prints_no_reason_line(): void
    {
        Drain::start(60);

        $this->assertStringNotContainsString('Reason:', $this->runArtisan('drain:status')[1]);
    }

    public function test_status_can_print_json(): void
    {
        $this->assertStringContainsString('{"draining":false}', $this->runArtisan('drain:status --json')[1]);

        Drain::start(60, 'deploy');

        $state = json_decode($this->runArtisan('drain:status --json')[1], true);

        $this->assertTrue($state['draining']);
        $this->assertSame('deploy', $state['reason']);
    }

    public function test_artisan_up_clears_the_drain(): void
    {
        Drain::start(60);

        $this->assertSame(0, $this->runArtisan('down')[0]);
        $this->assertSame(0, $this->runArtisan('up')[0]);

        $this->assertFalse(Drain::isDraining());
    }
}
