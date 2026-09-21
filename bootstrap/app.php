<?php

use App\Exceptions\FinanceRuleException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Inbound integration (P-INT) — API-key gate for the PUSH endpoints.
        $middleware->alias([
            'integration.client' => \App\Http\Middleware\EnsureIntegrationClient::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule violations surface as 422 with a clean message,
        // never a 500/stack trace. See App\Exceptions\FinanceRuleException.
        $exceptions->render(function (FinanceRuleException $e, Request $request): ?JsonResponse {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        });
    })->create();
