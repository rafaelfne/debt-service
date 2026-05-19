<?php

declare(strict_types=1);

use App\Domain\Debt\UnknownDebtTypeException;
use App\Domain\Plate\InvalidPlateException;
use App\Infrastructure\Http\Middleware\DemoRequestLogger;
use App\Infrastructure\Http\Middleware\MaxBodySize;
use App\Infrastructure\Resilience\AllProvidersUnavailableException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'max_body_size' => MaxBodySize::class,
        ]);

        // Demo tracer wraps every api request with → / ← lines on stderr.
        // No-op in production via the DemoLogger constructor flag.
        $middleware->api(append: [DemoRequestLogger::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $jsonOnly = static fn (Request $request): bool => $request->is('api/*')
            || $request->wantsJson()
            || $request->expectsJson();

        $exceptions->render(function (InvalidPlateException $e, Request $request) use ($jsonOnly) {
            if (! $jsonOnly($request)) {
                return null;
            }

            return response()->json(['error' => 'invalid_plate'], 400);
        });

        $exceptions->render(function (UnknownDebtTypeException $e, Request $request) use ($jsonOnly) {
            if (! $jsonOnly($request)) {
                return null;
            }

            return response()->json([
                'error' => 'unknown_debt_type',
                'type' => $e->type,
            ], 422);
        });

        $exceptions->render(function (AllProvidersUnavailableException $e, Request $request) use ($jsonOnly) {
            if (! $jsonOnly($request)) {
                return null;
            }

            return response()->json(['error' => 'all_providers_unavailable'], 503);
        });
    })->create();
