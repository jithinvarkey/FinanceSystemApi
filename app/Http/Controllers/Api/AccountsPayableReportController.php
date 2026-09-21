<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\AccountsPayableReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * P3.17 — Accounts Payable reports: AP aging and the per-vendor statement.
 * Read-only; gated on accounts-payable.view.
 */
final class AccountsPayableReportController extends Controller
{
    public function __construct(private readonly AccountsPayableReportService $service)
    {
    }

    public function aging(Request $request): JsonResponse
    {
        $this->authorize('accounts-payable.view');

        $request->validate([
            'as_of' => ['nullable', 'date'],
            'basis' => ['nullable', 'in:due_date,invoice_date'],
        ]);

        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        return response()->json([
            'data' => $this->service->aging($asOf, $request->string('basis', 'due_date')->toString()),
        ]);
    }

    public function vendorLedger(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('accounts-payable.view');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;

        return response()->json([
            'data' => $this->service->vendorLedger($vendor, $from, $to),
        ]);
    }
}
