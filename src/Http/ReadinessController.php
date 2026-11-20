<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NickAguilarH\Readiness\Drain;
use NickAguilarH\Readiness\Readiness;
use NickAguilarH\Readiness\Report;
use NickAguilarH\Readiness\Support\Access;

final class ReadinessController
{
    public function __invoke(Request $request, Readiness $readiness, Access $access): JsonResponse
    {
        if (! $access->allows($request)) {
            abort(403);
        }

        $report = $readiness->run();

        $response = response()->json($report->toArray(), $report->httpStatus())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');

        if ($report->status() === Report::DRAINING) {
            $remaining = Drain::status()['remaining'] ?? 0;

            if ($remaining > 0) {
                $response->header('Retry-After', (string) $remaining);
            }
        }

        return $response;
    }
}
