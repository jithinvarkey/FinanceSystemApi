<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RiskScanService;
use Illuminate\Http\JsonResponse;

/**
 * E3 — Risk & anomaly scan.
 */
final class RiskController extends Controller
{
    public function __construct(private readonly RiskScanService $service)
    {
    }

    public function scan(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => $this->service->scan()]);
    }
}
