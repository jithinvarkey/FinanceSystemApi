<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\IntegrationEventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IngestEndorsementRequest;
use App\Http\Requests\IngestPolicyRequest;
use App\Http\Requests\IngestRenewalRequest;
use App\Http\Resources\IntegrationEventResource;
use App\Models\IntegrationClient;
use App\Models\IntegrationEvent;
use App\Services\IntegrationIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound integration (P-INT) — the PUSH endpoints. The upstream policy-admin
 * system POSTs policy / endorsement / renewal events here; each lands as a
 * DRAFT for finance review (never auto-posted). Auth is the integration-key
 * middleware, which stashes the resolved client on the request.
 *
 * 201 = a new draft was created; 200 = duplicate (already ingested, no-op).
 */
final class IntegrationController extends Controller
{
    public function __construct(private readonly IntegrationIngestService $service)
    {
    }

    public function policy(IngestPolicyRequest $request): JsonResponse
    {
        return $this->respond($this->service->ingestPolicy($request->validated(), $this->client($request)));
    }

    public function endorsement(IngestEndorsementRequest $request): JsonResponse
    {
        return $this->respond($this->service->ingestEndorsement($request->validated(), $this->client($request)));
    }

    public function renewal(IngestRenewalRequest $request): JsonResponse
    {
        return $this->respond($this->service->ingestRenewal($request->validated(), $this->client($request)));
    }

    private function client(Request $request): IntegrationClient
    {
        /** @var IntegrationClient $client */
        $client = $request->attributes->get('integration_client');

        return $client;
    }

    private function respond(IntegrationEvent $event): JsonResponse
    {
        $status = $event->status === IntegrationEventStatus::Duplicate ? 200 : 201;

        return (new IntegrationEventResource($event->load('target')))->response()->setStatusCode($status);
    }
}
