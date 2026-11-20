<?php

declare(strict_types=1);

namespace Readiness\Checks;

use Illuminate\Contracts\Redis\Factory;
use Readiness\Support\Probe;

final class RedisCheck implements Check
{
    public function __construct(
        private Factory $redis,
        private ?string $connection = null,
        private float $timeout = 1.0,
    ) {}

    public function name(): string
    {
        return 'redis';
    }

    public function run(Probe $probe): array
    {
        return $probe->run($this->timeout, function (): void {
            $this->redis->connection($this->connection)->ping();
        });
    }
}
