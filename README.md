# Laravel Readiness

A readiness probe toolkit for Laravel. Its main feature is **drain mode**: tell
one instance to answer `503` on its readiness route while it keeps serving
real traffic, so the load balancer has time to take it out of rotation before
you run `artisan down`, migrate or restart.

It also ships the bounded checks a readiness endpoint usually needs (database,
Redis, Horizon), a probe runner with one shared time budget, and a token/IP
guard for the report.

```
php artisan drain --wait=30   # not ready from now on, still serving. Waits 30 s.
php artisan down              # now nobody is routed here
# migrate, restart, deploy
php artisan up                # also clears the drain
```

Requires PHP 8.1+ and Laravel 8.83 to 12. It adds no third-party dependencies
and does not need Spatie Health.

> A package for Spatie Health with a similar drain check exists:
> [rpungello/draining-health-check](https://github.com/rpungello/draining-health-check).
> This one does not depend on Spatie, runs on older Laravel versions, and the
> drain expires by itself.

## Install

```
composer require nickaguilarh/laravel-readiness
php artisan vendor:publish --tag=readiness-config   # optional
```

The service provider is discovered automatically.

## Use it

Pick the option that costs what you can afford.

### 1. Inside your own route, no middleware

The hot path is a single `stat` call. No container, no config, no file read.

```php
use NickAguilarH\Readiness\Drain;

Route::get('/readyz', function () {
    if (Drain::isDraining()) {
        return Drain::unavailable(); // 503 {"status":"draining"}, Retry-After, no-store
    }

    // ...your own checks
});
```

### 2. As middleware on a route you already have

```php
Route::get('/readyz', ReadinessHandler::class)->middleware('readiness.drain');
```

### 3. The complete report

Run every configured check and get a `Report`:

```php
$report = app(\NickAguilarH\Readiness\Readiness::class)->run();

return response()->json($report->toArray(), $report->httpStatus());
```

Or let the package register the route. Set `READINESS_ROUTE_ENABLED=true` and
it serves `GET /readyz` (`READINESS_ROUTE_PATH` to change it), guarded by
[access](#access).

```json
{
  "status": "ready",
  "timestamp": "2026-11-20T10:00:00+00:00",
  "duration_ms": 4.31,
  "checks": {
    "draining": { "status": "ok", "duration_ms": 0.0 },
    "maintenance": { "status": "ok", "duration_ms": 0.0 },
    "database": { "status": "ok", "duration_ms": 1.2, "driver": "mysql" },
    "redis": { "status": "ok", "duration_ms": 0.4 },
    "horizon": { "status": "skipped", "duration_ms": 0.0, "message": "laravel/horizon is not installed" },
    "stale_drain": { "status": "ok", "duration_ms": 0.0 }
  }
}
```

`status` is `ready` (HTTP 200), `not_ready` or `draining` (HTTP 503).

## Drain mode

| Command | What it does |
| --- | --- |
| `drain [--ttl=900] [--wait=0] [--reason=]` | Mark the instance as draining. `--wait` sleeps so the balancer can notice. |
| `undrain` | Clear the marker. |
| `drain:status [--json]` | Show whether the instance is draining and for how long. |

How it behaves:

- **Per instance.** The marker is a file on the instance's own disk. It is never
  shared, because draining one node must not drain the others.
- **It expires.** `--ttl` (default 900 s, at most `max_ttl`, 3600 s) is when it
  ends by itself, so a forgotten drain cannot keep a node out forever. The
  expiry is the file's modification time, which is why checking it costs one
  `stat`.
- **`artisan up` clears it** (`clear_on_up`), on Laravel versions that fire the
  maintenance event, which includes 8.83.
- **While draining, the report skips the other checks** (`short_circuit`), so the
  probe stays cheap exactly when the node is leaving.

Choose `--wait` from your balancer's settings:
`check interval × unhealthy threshold + margin`. With a check every 5 s, 3
failures to mark unhealthy and 10 s of margin, that is 25 s. After that, give
in-flight requests time to finish (php-fpm `request_terminate_timeout`) before
migrating. `artisan down` does not wait for requests already running.

The user running `artisan` needs write access to the marker's directory and
the web server user needs to read the marker (it is written `0644`).

## Checks

Configured in `config/readiness.php`, in order. Remove one to turn it off.

| Check | Fails when |
| --- | --- |
| `draining` | A drain is active. |
| `maintenance` | `artisan down` is active. |
| `database` | `select 1` fails or overruns. On MySQL it is bounded on the server with `MAX_EXECUTION_TIME`. |
| `redis` | `PING` fails or overruns. Skipped if Redis is not available. |
| `horizon` | No master supervisor, or one is paused. Skipped if Horizon is not installed. |
| `stale_drain` | Never fails, only warns when a drain is older than `stale_after`. |

Add your own by implementing `NickAguilarH\Readiness\Checks\Check` and listing
the class. It is resolved from the container.

Each check has its own timeout and all of them share `budget` (3 s). A check that
would start after the budget is spent is reported as `timed_out` without running.
`skipped` and `warning` never decide the verdict.

## Access

The report is served to a caller from `allowed_ips` or one sending the token in
`X-Readiness-Token`. **With neither configured, nobody gets in.**

```
READINESS_ALLOWED_IPS=10.0.0.0/8,2001:db8::/32
READINESS_TOKEN=a-long-random-value
```

Behind a proxy or a load balancer, configure trusted proxies so
`$request->ip()` is the real client.

Exception messages are replaced with `check failed`. Set `READINESS_EXPOSE_ERRORS=true`
to see them. Driver errors often contain hosts and usernames.

## Performance

`benchmarks/` uses [PHPBench](https://phpbench.readthedocs.io). Sample run on a
laptop with PHP 8.3, no opcache, 2000 revolutions × 10 iterations:

| Subject | Mean |
| --- | --- |
| `Drain::isDraining()`, not draining | 1.09 µs |
| `Drain::isDraining()`, draining | 1.21 µs |
| Middleware, pass through | 1.25 µs |
| Middleware, draining (builds the 503) | 25.3 µs |
| Access check by CIDR | 0.48 µs |
| Probe overhead per check | 0.46 µs |

Run them with `composer bench`. Treat the numbers as an order of magnitude, a
shared CI runner is noisier than a laptop.

## Development

```
composer test        # PHPUnit
composer coverage    # needs pcov or Xdebug
composer analyse     # PHPStan, level 8
composer lint        # Pint
composer bench       # PHPBench
composer security    # advisories (composer audit)
```

Older Laravel lines have open security advisories, and Composer refuses to
install them while those apply (CI hit this on 8, 10 and 11). To test the package
on one of them, set `composer config policy.advisories.block false`, as CI
does. Running a Laravel version with open advisories is your decision.

## Security

See [SECURITY.md](SECURITY.md) to report a vulnerability.

## License

MIT.
