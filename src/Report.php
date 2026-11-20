<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness;

use NickAguilarH\Readiness\Support\Probe;

final class Report
{
    public const READY = 'ready';

    public const NOT_READY = 'not_ready';

    public const DRAINING = 'draining';

    /**
     * @param  array<string, array<string, mixed>>  $checks
     */
    public function __construct(
        private array $checks,
        private float $durationMs,
        private bool $draining = false,
    ) {}

    public function status(): string
    {
        if ($this->draining) {
            return self::DRAINING;
        }

        return Probe::failed($this->checks) ? self::NOT_READY : self::READY;
    }

    public function ready(): bool
    {
        return $this->status() === self::READY;
    }

    public function httpStatus(): int
    {
        return $this->ready() ? 200 : 503;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status(),
            'timestamp' => gmdate('c'),
            'duration_ms' => $this->durationMs,
            'checks' => $this->checks,
        ];
    }
}
