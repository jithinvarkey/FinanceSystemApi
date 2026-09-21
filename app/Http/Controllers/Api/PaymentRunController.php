<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendorInvoice;
use App\Services\PaymentRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AP batch payment run. Reuses the AP permission set.
 */
final class PaymentRunController extends Controller
{
    public function __construct(private readonly PaymentRunService $service)
    {
    }

    /** Every posted vendor invoice with an outstanding balance, across all vendors. */
    public function outstanding(Request $request): JsonResponse
    {
        $this->authorize('accounts-payable.view');

        $rows = VendorInvoice::query()
            ->where('status', 'posted')
            ->with('vendor:id,name,vendor_code')
            ->orderBy('due_date')
            ->get()
            ->filter(fn (VendorInvoice $i): bool => $i->balanceDue() > 0)
            ->map(fn (VendorInvoice $i): array => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'vendor_id' => $i->vendor_id,
                'vendor_name' => $i->vendor?->name,
                'invoice_date' => $i->invoice_date?->toDateString(),
                'due_date' => $i->due_date?->toDateString(),
                'balance_due' => number_format($i->balanceDue(), 2, '.', ''),
            ])->values();

        return response()->json(['data' => $rows]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['integer', 'exists:vendor_invoices,id'],
            'bank_account_id' => ['required', 'exists:chart_of_accounts,id'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['nullable', 'in:bank_transfer,cheque,cash,online'],
        ]);

        $result = $this->service->run(
            array_map('intval', $data['invoice_ids']),
            (int) $data['bank_account_id'],
            $data['payment_date'],
            $data['payment_method'] ?? 'bank_transfer',
            (int) $request->user()->id,
        );

        return response()->json(['data' => $result]);
    }
}
