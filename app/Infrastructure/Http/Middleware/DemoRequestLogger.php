<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use App\Infrastructure\Logging\DemoLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps every request with a "→ method path" line on entry and a
 * "← status (elapsed)" line on exit. Exceptions raised inside the pipeline
 * are converted to responses by the framework's exception handler before
 * returning to this middleware, so the exit log also covers error paths.
 */
final class DemoRequestLogger
{
    public function __construct(private readonly DemoLogger $log) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $this->log->requestStarted(
            method: $request->method(),
            path: $request->path(),
            plate: (string) $request->input('placa', ''),
        );

        $response = $next($request);

        $this->log->requestCompleted(
            statusCode: $response->getStatusCode(),
            totalSeconds: microtime(true) - $start,
        );

        return $response;
    }
}
