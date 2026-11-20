<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Console;

use Illuminate\Console\Command;
use NickAguilarH\Readiness\Drain;

final class UndrainCommand extends Command
{
    protected $signature = 'undrain';

    protected $description = 'Report this instance as ready again';

    public function handle(): int
    {
        if (! Drain::stop()) {
            $this->info('This instance was not draining.');

            return self::SUCCESS;
        }

        $this->info('Drain cleared.');

        return self::SUCCESS;
    }
}
