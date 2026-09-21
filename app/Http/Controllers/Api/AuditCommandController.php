<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditCommandService;
use Illuminate\Http\JsonResponse;

/**
 * G1 — Audit Command Center: one governance overview.
 */
final class AuditCommandController extends Controller
{
    public function __construct(private readonly AuditCommandService $service)
    {
    }

    public function overview(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => $this->service->overview()]);
    }
}
