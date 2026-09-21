<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Services\LeaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * E14 — IFRS 16 lease register.
 */
final class LeaseController extends Controller
{
    public function __construct(private readonly LeaseService $service)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => Lease::query()->with('lines')->latest('id')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $account = ['required', 'integer', 'exists:chart_of_accounts,id'];
        $data = $request->validate([
            'description' => ['required', 'string', 'max:180'],
            'lessor' => ['nullable', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'monthly_payment' => ['required', 'numeric', 'min:0.01'],
            'discount_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rou_asset_account_id' => $account,
            'lease_liability_account_id' => $account,
            'interest_expense_account_id' => $account,
            'depreciation_expense_account_id' => $account,
            'bank_account_id' => $account,
        ]);

        $lease = $this->service->createLease($data, (int) $request->user()->id);

        return response()->json(['data' => $lease], 201);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate(['as_of' => ['required', 'date']]);

        return response()->json(['data' => $this->service->runMonthly(Carbon::parse($data['as_of']), (int) $request->user()->id)]);
    }
}
