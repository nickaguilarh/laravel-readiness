<?php

declare(strict_types=1);

namespace Readiness\Checks;

use Illuminate\Database\DatabaseManager;
use Readiness\Support\Probe;

final class DatabaseCheck implements Check
{
    public function __construct(
        private DatabaseManager $database,
        private ?string $connection = null,
        private float $timeout = 1.0,
    ) {}

    public function name(): string
    {
        return 'database';
    }

    public function run(Probe $probe): array
    {
        return $probe->run($this->timeout, function (float $allowed): array {
            $connection = $this->database->connection($this->connection);

            if ($connection->getDriverName() === 'mysql') {
                // A server side kill is the only way to bound a query from PHP.
                $connection->select(sprintf(
                    'select /*+ MAX_EXECUTION_TIME(%d) */ 1',
                    max(1, (int) ceil($allowed * 1000))
                ));
            } else {
                $connection->select('select 1');
            }

            return ['driver' => $connection->getDriverName()];
        });
    }
}
