<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound integration (P-INT) — API-key gate for the PUSH endpoints. The
 * upstream system presents its key in X-Integration-Key (or a Bearer token);
 * we resolve the active client and stash it on the request for the controller.
 * This is machine auth — entirely separate from the Sanctum user guard.
 */
final class EnsureIntegrationClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Integration-Key') ?? $request->bearerToken() ?? '';

        $client = IntegrationClient::resolve((string) $key);

        if (! $client) {
            return response()->json(['message' => 'Invalid or missing integration key.'], 401);
        }

        $client->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('integration_client', $client);

        return $next($request);
    }
}
