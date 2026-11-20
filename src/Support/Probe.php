<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Support;

use Throwable;

/**
 * Runs checks under a shared wall-clock budget.
 *
 * PHP cannot interrupt a blocking syscall, so every check receives its own
 * allowance and is expected to hand it down to whatever actually blocks (a PDO
 * connect timeout, a Redis read timeout, ...). The probe then reports a check
 * that overran as timed out.
 */
final class Probe
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_WARNING = 'warning';

    private float $startedAt;

    private float $deadline;

    public function __construct(float $budget, private bool $exposeErrors = false)
    {
        $this->startedAt = self::now();
        $this->deadline = $this->startedAt + max(0.0, $budget);
    }

    public function remaining(): float
    {
        return max(0.0, $this->deadline - self::now());
    }

    public function elapsedMs(): float
    {
        return round((self::now() - $this->startedAt) * 1000, 2);
    }

    /**
     * Run one check under the smaller of its own timeout and the budget left.
     *
     * The callback receives its allowance in seconds and may return details to
     * merge into the result.
     *
     * @param  callable(float): mixed  $check  Returns details to merge, or nothing.
     * @return array<string, mixed>
     */
    public function run(float $timeout, callable $check): array
    {
        $allowed = min($timeout, $this->remaining());

        if ($allowed <= 0) {
            return [
                'status' => self::STATUS_TIMED_OUT,
                'duration_ms' => 0.0,
                'message' => 'deadline exceeded before check ran',
            ];
        }

        $startedAt = self::now();

        try {
            $details = $check($allowed);
            $details = is_array($details) ? $details : [];
            $elapsed = self::now() - $startedAt;

            return array_merge([
                'status' => $elapsed > $allowed ? self::STATUS_TIMED_OUT : self::STATUS_OK,
                'duration_ms' => self::since($startedAt),
            ], $details);
        } catch (Throwable $exception) {
            return [
                'status' => self::STATUS_FAILED,
                'duration_ms' => self::since($startedAt),
                // Driver exceptions routinely carry hosts and usernames, so
                // the message only leaves the process when asked for.
                'message' => $this->exposeErrors ? $exception->getMessage() : 'check failed',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function skipped(string $message, array $details = []): array
    {
        return array_merge([
            'status' => self::STATUS_SKIPPED,
            'duration_ms' => 0.0,
            'message' => $message,
        ], $details);
    }

    /**
     * True when any check failed or timed out. Skipped and warning results
     * never decide the verdict.
     *
     * @param  array<string, mixed>  $checks
     */
    public static function failed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            if (in_array($check['status'] ?? null, [self::STATUS_FAILED, self::STATUS_TIMED_OUT], true)) {
                return true;
            }
        }

        return false;
    }

    private static function since(float $startedAt): float
    {
        return round((self::now() - $startedAt) * 1000, 2);
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
