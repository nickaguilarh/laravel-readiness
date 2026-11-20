<?php

declare(strict_types=1);

namespace Readiness\Http;

use Closure;
use Illuminate\Http\Request;
use Readiness\Drain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 503 while the instance is draining. Put it on a readiness route you
 * already have, alias: readiness.drain.
 */
final class RejectWhileDraining
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Drain::isDraining()) {
            return Drain::unavailable();
        }

        return $next($request);
    }
}
