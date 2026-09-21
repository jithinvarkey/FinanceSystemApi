<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerAdvance;
use App\Services\CustomerAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * On-account customer receipts (advances). Reuses the AR permission set.
 */
final class CustomerAdvanceController extends Controller
{
    public function __construct(private readonly CustomerAdvanceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('accounts-receivable.view');

        $rows = CustomerAdvance::query()->with('customer:id,name')
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->latest('id')->limit(200)->get()->map(fn (CustomerAdvance $a): array => $this->shape($a));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('accounts-receivable.manage');
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'receipt_date' => ['required', 'date'],
            'bank_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'advance_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('is_postable', true)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->shape($this->service->createDraft($data, (int) $request->user()->id)->load('customer'))], 201);
    }

    public function post(Request $request, CustomerAdvance $customerAdvance): JsonResponse
    {
        $this->authorize('accounts-receivable.post');

        return response()->json(['data' => $this->shape($this->service->post($customerAdvance, (int) $request->user()->id))]);
    }

    public function apply(Request $request, CustomerAdvance $customerAdvance): JsonResponse
    {
        $this->authorize('accounts-receivable.post');
        $data = $request->validate([
            'customer_invoice_id' => ['required', Rule::exists('customer_invoices', 'id')],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        return response()->json(['data' => $this->shape($this->service->apply($customerAdvance, (int) $data['customer_invoice_id'], (float) $data['amount'], (int) $request->user()->id))]);
    }

    private function shape(CustomerAdvance $a): array
    {
        return [
            'id' => $a->id, 'advance_number' => $a->advance_number, 'customer_id' => $a->customer_id,
            'customer_name' => $a->customer?->name, 'receipt_date' => $a->receipt_date?->toDateString(),
            'amount' => $a->amount, 'applied_amount' => $a->applied_amount,
            'unapplied' => number_format($a->unapplied(), 2, '.', ''),
            'status' => $a->status->value, 'reference' => $a->reference,
        ];
    }
}
