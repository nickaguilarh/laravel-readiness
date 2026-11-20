<?php

declare(strict_types=1);

namespace Readiness\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Readiness\Report;

final class ReportTest extends TestCase
{
    public function test_it_is_ready_when_nothing_failed(): void
    {
        $report = new Report(['database' => ['status' => 'ok']], 1.5);

        $this->assertTrue($report->ready());
        $this->assertSame(200, $report->httpStatus());
        $this->assertSame('ready', $report->toArray()['status']);
        $this->assertSame(1.5, $report->toArray()['duration_ms']);
        $this->assertSame(['database' => ['status' => 'ok']], $report->checks());
    }

    public function test_it_is_not_ready_when_a_check_failed(): void
    {
        $report = new Report(['database' => ['status' => 'failed']], 1.0);

        $this->assertSame('not_ready', $report->status());
        $this->assertSame(503, $report->httpStatus());
    }

    public function test_draining_wins_over_the_checks(): void
    {
        $report = new Report(['draining' => ['status' => 'failed']], 0.1, true);

        $this->assertSame('draining', $report->status());
        $this->assertSame(503, $report->httpStatus());
    }

    public function test_a_warning_does_not_change_the_verdict(): void
    {
        $this->assertTrue((new Report(['stale_drain' => ['status' => 'warning']], 0.1))->ready());
    }

    public function test_the_timestamp_is_iso_8601(): void
    {
        $timestamp = (new Report([], 0.0))->toArray()['timestamp'];

        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $timestamp));
    }
}
