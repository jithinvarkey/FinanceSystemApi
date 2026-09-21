<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IntegrationEventResource;
use App\Models\IntegrationClient;
use App\Models\IntegrationEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Inbound integration (P-INT) — read-only admin views over the sync log and the
 * registered upstream clients. User-facing (Sanctum + finance-config.view),
 * distinct from the machine PUSH endpoints.
 */
final class IntegrationEventController extends Controller
{
    /** Most recent ingest events, newest first (capped — this is a live log, not an archive). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('finance-config.view');

        $events = IntegrationEvent::query()
            ->with('client')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->string('event_type')))
            ->when($request->filled('source_system'), fn ($q) => $q->where('source_system', $request->string('source_system')))
            ->when($request->filled('q'), fn ($q) => $q->where('external_id', 'like', '%'.$request->string('q').'%'))
            ->latest()
            ->limit(200)
            ->get();

        return IntegrationEventResource::collection($events);
    }

    /** Registered upstream clients (no secrets). */
    public function clients(Request $request): JsonResponse
    {
        $this->authorize('finance-config.view');

        $clients = IntegrationClient::query()
            ->withCount('events')
            ->orderBy('name')
            ->get()
            ->map(fn (IntegrationClient $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'source_system' => $c->source_system,
                'is_active' => $c->is_active,
                'events_count' => $c->events_count,
                'last_used_at' => $c->last_used_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $clients]);
    }
}
