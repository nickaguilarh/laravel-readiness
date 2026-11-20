<?php

declare(strict_types=1);

namespace Readiness\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Readiness\Support\Probe;
use RuntimeException;

final class ProbeTest extends TestCase
{
    public function test_a_passing_check_is_ok_and_merges_its_details(): void
    {
        $result = (new Probe(1.0))->run(1.0, fn () => ['driver' => 'mysql']);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('mysql', $result['driver']);
        $this->assertArrayHasKey('duration_ms', $result);
    }

    public function test_the_check_receives_its_allowance(): void
    {
        $received = null;

        (new Probe(10.0))->run(0.5, function (float $allowed) use (&$received) {
            $received = $allowed;
        });

        $this->assertEqualsWithDelta(0.5, $received, 0.001);
    }

    public function test_the_allowance_is_capped_by_what_is_left_of_the_budget(): void
    {
        $received = null;

        (new Probe(0.2))->run(5.0, function (float $allowed) use (&$received) {
            $received = $allowed;
        });

        $this->assertLessThanOrEqual(0.2, $received);
    }

    public function test_an_exception_fails_the_check_without_leaking_its_message(): void
    {
        $result = (new Probe(1.0))->run(1.0, function () {
            throw new RuntimeException('SQLSTATE host=10.1.2.3 user=root');
        });

        $this->assertSame('failed', $result['status']);
        $this->assertSame('check failed', $result['message']);
    }

    public function test_the_message_is_exposed_only_on_request(): void
    {
        $result = (new Probe(1.0, exposeErrors: true))->run(1.0, function () {
            throw new RuntimeException('boom');
        });

        $this->assertSame('boom', $result['message']);
    }

    public function test_a_check_that_overruns_is_timed_out(): void
    {
        $result = (new Probe(1.0))->run(0.01, function () {
            usleep(30000);
        });

        $this->assertSame('timed_out', $result['status']);
    }

    public function test_a_check_is_not_run_once_the_budget_is_spent(): void
    {
        $ran = false;
        $result = (new Probe(0.0))->run(1.0, function () use (&$ran) {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertSame('timed_out', $result['status']);
        $this->assertSame(0.0, $result['duration_ms']);
    }

    public function test_remaining_and_elapsed_track_the_clock(): void
    {
        $probe = new Probe(5.0);

        $this->assertGreaterThan(4.0, $probe->remaining());
        $this->assertGreaterThanOrEqual(0.0, $probe->elapsedMs());
    }

    public function test_a_negative_budget_is_treated_as_empty(): void
    {
        $this->assertSame(0.0, (new Probe(-1.0))->remaining());
    }

    public function test_skipped_carries_a_message(): void
    {
        $result = Probe::skipped('not installed', ['extra' => 1]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('not installed', $result['message']);
        $this->assertSame(1, $result['extra']);
    }

    public function test_only_failed_and_timed_out_decide_the_verdict(): void
    {
        $this->assertFalse(Probe::failed(['a' => ['status' => 'ok'], 'b' => ['status' => 'warning'], 'c' => ['status' => 'skipped'], 'd' => 'not an array']));
        $this->assertTrue(Probe::failed(['a' => ['status' => 'ok'], 'b' => ['status' => 'failed']]));
        $this->assertTrue(Probe::failed(['a' => ['status' => 'timed_out']]));
    }
}
