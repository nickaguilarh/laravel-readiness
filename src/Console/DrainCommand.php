<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use NickAguilarH\Readiness\Drain;
use RuntimeException;

final class DrainCommand extends Command
{
    protected $signature = 'drain
        {--ttl= : Seconds before the drain expires on its own}
        {--wait=0 : Seconds to wait after marking, for the load balancer to notice}
        {--reason= : A short note shown by drain:status}';

    protected $description = 'Report this instance as not ready while it keeps serving traffic';

    public function handle(): int
    {
        $ttl = $this->integerOption('ttl', (int) config('readiness.drain.ttl', Drain::DEFAULT_TTL));
        $wait = $this->integerOption('wait', 0);

        if ($ttl === null || $wait === null || $wait < 0) {
            $this->error('The --ttl and --wait options must be whole numbers of seconds.');

            return self::FAILURE;
        }

        if ($wait > $ttl) {
            $this->error('The --wait option cannot be longer than --ttl, the drain would expire while waiting.');

            return self::FAILURE;
        }

        $reason = $this->option('reason');

        try {
            $state = Drain::start($ttl, is_string($reason) ? $reason : null);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Draining until %s (%d seconds).', gmdate('c', $state['expires_at']), $ttl));

        if ($wait > 0) {
            $this->line(sprintf('Waiting %d seconds for the load balancer to notice.', $wait));
            sleep($wait);
        }

        return self::SUCCESS;
    }

    private function integerOption(string $name, int $default): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return $default;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
