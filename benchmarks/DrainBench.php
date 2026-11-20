<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Benchmarks;

use Illuminate\Http\Request;
use NickAguilarH\Readiness\Drain;
use NickAguilarH\Readiness\Http\RejectWhileDraining;
use NickAguilarH\Readiness\Support\Access;
use NickAguilarH\Readiness\Support\Probe;
use PhpBench\Attributes as Bench;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request cost of the readiness building blocks.
 *
 * isDraining() is what a route closure calls directly, the middleware wraps
 * the same call, so the difference between the two is the cost of using the
 * middleware instead of the direct call. The assertions are deliberately
 * loose, they catch an accidental regression (a file read or a container
 * lookup on the hot path), not a slow machine.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\AfterMethods('tearDown')]
#[Bench\Iterations(10)]
#[Bench\Revs(2000)]
#[Bench\Warmup(2)]
final class DrainBench
{
    private string $directory;

    private Request $request;

    private RejectWhileDraining $middleware;

    private Access $access;

    private Response $response;

    public function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/readiness-bench-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);

        Drain::configure($this->directory.'/drain');

        $this->request = Request::create('/readyz', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3']);
        $this->middleware = new RejectWhileDraining;
        $this->response = new Response;
        $this->access = new Access(['10.0.0.0/8', '192.168.0.0/16'], 'token');
    }

    public function tearDown(): void
    {
        Drain::stop();
        Drain::configure(null);

        @rmdir($this->directory);
    }

    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchIsDrainingWhenNotDraining(): void
    {
        Drain::isDraining();
    }

    #[Bench\BeforeMethods('startDrain')]
    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchIsDrainingWhenDraining(): void
    {
        Drain::isDraining();
    }

    #[Bench\Assert('mode(variant.time.avg) < 40 microseconds')]
    public function benchMiddlewarePassThrough(): void
    {
        $this->middleware->handle($this->request, fn () => $this->response);
    }

    #[Bench\BeforeMethods('startDrain')]
    #[Bench\Assert('mode(variant.time.avg) < 200 microseconds')]
    public function benchMiddlewareWhenDraining(): void
    {
        $this->middleware->handle($this->request, fn () => $this->response);
    }

    #[Bench\Assert('mode(variant.time.avg) < 40 microseconds')]
    public function benchAccessCheckByCidr(): void
    {
        $this->access->allows($this->request);
    }

    #[Bench\Assert('mode(variant.time.avg) < 20 microseconds')]
    public function benchProbeRunOverhead(): void
    {
        (new Probe(3.0))->run(1.0, static fn () => null);
    }

    public function startDrain(): void
    {
        Drain::start(3600);
    }
}
