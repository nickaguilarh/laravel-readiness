<?php

declare(strict_types=1);

namespace Readiness\Tests\Unit;

use InvalidArgumentException;
use Readiness\Drain;
use Readiness\Tests\TestCase;
use RuntimeException;

final class DrainTest extends TestCase
{
    public function test_it_is_not_draining_by_default(): void
    {
        $this->assertFalse(Drain::isDraining());
        $this->assertNull(Drain::status());
    }

    public function test_it_is_not_draining_when_no_path_is_configured(): void
    {
        Drain::configure(null);

        $this->assertFalse(Drain::isDraining());
        $this->assertNull(Drain::path());
    }

    public function test_start_marks_the_instance_as_draining(): void
    {
        $state = Drain::start(60, 'deploy');

        $this->assertTrue(Drain::isDraining());
        $this->assertSame($state['started_at'] + 60, $state['expires_at']);
        $this->assertSame('deploy', $state['reason']);
    }

    public function test_the_drain_expires_on_its_own(): void
    {
        Drain::start(60, now: time() - 120);

        $this->assertFalse(Drain::isDraining());
        $this->assertNull(Drain::status());
    }

    public function test_stop_clears_the_marker(): void
    {
        Drain::start(60);

        $this->assertTrue(Drain::stop());
        $this->assertFalse(Drain::isDraining());
        $this->assertFalse(Drain::stop());
    }

    public function test_stop_removes_an_expired_marker_too(): void
    {
        Drain::start(60, now: time() - 120);

        $this->assertTrue(Drain::stop());
    }

    public function test_status_reports_remaining_age_and_reason(): void
    {
        $now = time();
        Drain::start(100, 'migrating', $now - 40);

        $status = Drain::status($now);

        $this->assertSame(60, $status['remaining']);
        $this->assertSame(40, $status['age']);
        $this->assertSame('migrating', $status['reason']);
    }

    public function test_the_reason_is_sanitised(): void
    {
        Drain::start(60, "line one\nline two\x00\x07 ".str_repeat('x', 500));

        $reason = Drain::status()['reason'];

        $this->assertStringNotContainsString("\n", $reason);
        $this->assertStringNotContainsString("\x00", $reason);
        $this->assertSame(200, mb_strlen($reason));
    }

    public function test_a_blank_reason_is_dropped(): void
    {
        Drain::start(60, "  \n ");

        $this->assertNull(Drain::status()['reason']);
    }

    public function test_it_rejects_a_ttl_out_of_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Drain::start(0);
    }

    public function test_it_rejects_a_ttl_above_the_maximum(): void
    {
        Drain::configure($this->marker.'/drain', 100);

        $this->expectException(InvalidArgumentException::class);

        Drain::start(101);
    }

    public function test_it_refuses_to_write_through_a_symlink(): void
    {
        $target = $this->marker.'/elsewhere';
        file_put_contents($target, 'keep');
        symlink($target, $this->marker.'/drain');

        try {
            Drain::start(60);
            $this->fail('A symlink marker must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('keep', file_get_contents($target));
        } finally {
            unlink($target);
        }
    }

    public function test_it_fails_when_the_directory_is_missing(): void
    {
        Drain::configure($this->marker.'/missing/drain');

        $this->expectException(RuntimeException::class);

        Drain::start(60);
    }

    public function test_it_fails_when_no_path_is_configured(): void
    {
        Drain::configure(null);

        $this->expectException(RuntimeException::class);

        Drain::start(60);
    }

    public function test_stop_fails_when_no_path_is_configured(): void
    {
        Drain::configure(null);

        $this->expectException(RuntimeException::class);

        Drain::stop();
    }

    public function test_the_marker_is_readable_by_other_users_and_leaves_no_temporary_file(): void
    {
        Drain::start(60);

        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->marker.'/drain')), -4));
        $this->assertSame(['drain'], array_values(array_diff(scandir($this->marker), ['.', '..'])));
    }

    public function test_a_corrupt_marker_still_drains_without_details(): void
    {
        file_put_contents($this->marker.'/drain', '{not json');
        touch($this->marker.'/drain', time() + 60);

        $status = Drain::status();

        $this->assertTrue(Drain::isDraining());
        $this->assertNull($status['started_at']);
        $this->assertNull($status['age']);
        $this->assertNull($status['reason']);
    }

    public function test_an_empty_marker_still_drains_without_details(): void
    {
        touch($this->marker.'/drain', time() + 60);

        $this->assertNull(Drain::status()['reason']);
    }

    public function test_the_unavailable_response_leaks_nothing(): void
    {
        Drain::start(120, 'secret maintenance reason');

        $response = Drain::unavailable();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('{"status":"draining"}', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertStringNotContainsString('secret', (string) $response);
    }

    public function test_the_unavailable_response_has_no_retry_after_without_a_drain(): void
    {
        $this->assertFalse(Drain::unavailable()->headers->has('Retry-After'));
    }

    public function test_a_stale_stat_cache_is_not_trusted(): void
    {
        Drain::start(60);
        $this->assertTrue(Drain::isDraining());

        unlink($this->marker.'/drain');

        $this->assertFalse(Drain::isDraining());
    }
}
