<?php

use App\Domain\Exceptions\AllProvidersUnavailableException;
use App\Domain\Exceptions\InvalidPlateException;
use App\Domain\Exceptions\UnknownDebtTypeException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
            \App\Http\Middleware\RequestIdMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (InvalidPlateException $e) =>
            response()->json(['error' => 'invalid_plate'], 400));

        $exceptions->render(fn (UnknownDebtTypeException $e) =>
            response()->json(['error' => 'unknown_debt_type', 'type' => $e->tipo], 422));

        $exceptions->render(fn (AllProvidersUnavailableException $e) =>
            response()->json(['error' => 'all_providers_unavailable'], 503));
    })->create();
