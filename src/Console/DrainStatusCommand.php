<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Console;

use Illuminate\Console\Command;
use NickAguilarH\Readiness\Drain;

final class DrainStatusCommand extends Command
{
    protected $signature = 'drain:status {--json : Print the state as JSON}';

    protected $description = 'Show whether this instance is draining';

    public function handle(): int
    {
        $status = Drain::status();

        if ($this->option('json')) {
            $this->line((string) json_encode(['draining' => $status !== null] + ($status ?? []), JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($status === null) {
            $this->info('Not draining.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Draining, %d seconds left.', $status['remaining']));

        if ($status['reason'] !== null) {
            $this->line('Reason: '.$status['reason']);
        }

        return self::SUCCESS;
    }
}
