<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\AccountsReceivableReportService;
use App\Services\DunningCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * P4.18 — Accounts Receivable reports: AR aging and the per-customer statement.
 * Read-only; gated on accounts-receivable.view.
 */
final class AccountsReceivableReportController extends Controller
{
    public function __construct(private readonly AccountsReceivableReportService $service)
    {
    }

    public function aging(Request $request): JsonResponse
    {
        $this->authorize('accounts-receivable.view');

        $request->validate([
            'as_of' => ['nullable', 'date'],
            'basis' => ['nullable', 'in:due_date,invoice_date'],
        ]);

        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        return response()->json([
            'data' => $this->service->aging($asOf, $request->string('basis', 'due_date')->toString()),
        ]);
    }

    public function dunning(Request $request): JsonResponse
    {
        $this->authorize('accounts-receivable.view');
        $request->validate(['as_of' => ['nullable', 'date']]);

        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        return response()->json(['data' => $this->service->dunning($asOf)]);
    }

    /** E4 — e-mail dunning reminders for the selected overdue invoices. */
    public function sendReminders(Request $request, DunningCollectionService $collections): JsonResponse
    {
        $this->authorize('accounts-receivable.manage');
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['integer'],
        ]);

        $result = $collections->send($data['invoice_ids'], (int) $request->user()->id);

        return response()->json(['data' => $result]);
    }

    public function dunningLog(DunningCollectionService $collections): JsonResponse
    {
        $this->authorize('accounts-receivable.view');

        return response()->json(['data' => $collections->recent()]);
    }

    public function customerLedger(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('accounts-receivable.view');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;

        return response()->json([
            'data' => $this->service->customerLedger($customer, $from, $to),
        ]);
    }
}
