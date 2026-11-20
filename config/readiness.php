<?php

use Readiness\Checks;

return [

    /*
    |--------------------------------------------------------------------------
    | Overall Probe Budget
    |--------------------------------------------------------------------------
    |
    | The wall-clock time, in seconds, a readiness probe may spend before it
    | has to answer. Checks that would start after the budget is spent are
    | reported as "timed_out" without being run.
    |
    */

    'budget' => (float) env('READINESS_BUDGET_SECONDS', 3.0),

    /*
    |--------------------------------------------------------------------------
    | Checks
    |--------------------------------------------------------------------------
    |
    | The checks to run, in order. Remove one to turn it off, or add your own
    | class implementing Readiness\Checks\Check. They are
    | resolved from the container.
    |
    */

    'checks' => [
        Checks\DrainingCheck::class,
        Checks\MaintenanceCheck::class,
        Checks\DatabaseCheck::class,
        Checks\RedisCheck::class,
        Checks\HorizonCheck::class,
        Checks\StaleDrainCheck::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per Check Options
    |--------------------------------------------------------------------------
    |
    | Timeouts are in seconds and each is also capped by what is left of the
    | overall budget. A null connection uses the application default.
    |
    */

    'database' => [
        'connection' => env('READINESS_DATABASE_CONNECTION'),
        'timeout' => (float) env('READINESS_DATABASE_TIMEOUT', 1.0),
    ],

    'redis' => [
        'connection' => env('READINESS_REDIS_CONNECTION'),
        'timeout' => (float) env('READINESS_REDIS_TIMEOUT', 1.0),
    ],

    'horizon' => [
        'timeout' => (float) env('READINESS_HORIZON_TIMEOUT', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Drain Mode
    |--------------------------------------------------------------------------
    |
    | path:          The marker file. Keep it on the instance's own disk, never
    |                on storage shared between instances. The web server user
    |                must be able to read it and the user running artisan must
    |                be able to write to its directory.
    | ttl:           Seconds a drain lasts when none is given. It expires on its
    |                own so a forgotten drain cannot keep an instance out.
    | max_ttl:       The longest drain that may be requested.
    | stale_after:   Age, in seconds, after which the stale drain check warns.
    | clear_on_up:   Clear the marker when `artisan up` runs.
    | short_circuit: While draining, answer without running the other checks.
    |
    */

    'drain' => [
        'path' => env('READINESS_DRAIN_PATH', storage_path('framework/readiness-drain')),
        'ttl' => (int) env('READINESS_DRAIN_TTL', 900),
        'max_ttl' => (int) env('READINESS_DRAIN_MAX_TTL', 3600),
        'stale_after' => (int) env('READINESS_DRAIN_STALE_AFTER', 1800),
        'clear_on_up' => (bool) env('READINESS_DRAIN_CLEAR_ON_UP', true),
        'short_circuit' => (bool) env('READINESS_DRAIN_SHORT_CIRCUIT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    |
    | Who may read the report. A caller from allowed_ips (comma separated
    | addresses or CIDR ranges in the environment), or one that sends the
    | token in the given header. With neither set, nobody can.
    |
    */

    'access' => [
        'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('READINESS_ALLOWED_IPS', ''))))),
        'token' => env('READINESS_TOKEN'),
        'header' => 'X-Readiness-Token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    |
    | Exceptions from drivers often contain hosts and usernames. They stay out
    | of the report unless you turn this on.
    |
    */

    'expose_errors' => (bool) env('READINESS_EXPOSE_ERRORS', false),

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | Register the readiness route. Leave it off to wire the package into a
    | route you already have, with Readiness::run(), the middleware or
    | Drain::isDraining().
    |
    */

    'route' => [
        'enabled' => (bool) env('READINESS_ROUTE_ENABLED', false),
        'path' => env('READINESS_ROUTE_PATH', 'readyz'),
        'middleware' => [],
    ],

];
