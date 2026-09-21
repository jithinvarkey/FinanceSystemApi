<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VatReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * P9 — ZATCA VAT return: output VAT − recoverable input VAT for a tax period.
 * Read-only; gated on general-ledger.view.
 */
final class VatReturnController extends Controller
{
    public function __construct(private readonly VatReturnService $service)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;

        return response()->json(['data' => $this->service->vatReturn($from, $to)]);
    }
}
