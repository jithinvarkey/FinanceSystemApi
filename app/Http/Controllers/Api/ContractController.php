<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * N5 — Contract & incentive register.
 */
final class ContractController extends Controller
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('finance-config.view');

        $contracts = Contract::query()
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->where('category', $c))
            ->latest('id')->limit(200)->get();

        return response()->json(['data' => $contracts]);
    }

    /** Contracts expiring within N days (default 60). */
    public function expiring(Request $request): JsonResponse
    {
        $this->authorize('finance-config.view');
        $horizon = Carbon::today()->addDays((int) $request->integer('days', 60));

        $rows = Contract::query()->where('status', 'active')
            ->whereNotNull('end_date')->whereDate('end_date', '<=', $horizon->toDateString())
            ->orderBy('end_date')->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $data = $this->validateContract($request);

        $contract = Contract::query()->create([
            ...$data,
            'contract_number' => $this->numbers->next('contract', Carbon::parse($data['start_date'])),
            'status' => 'active', 'created_by' => (int) $request->user()->id,
        ]);

        return response()->json(['data' => $contract], 201);
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $data = $this->validateContract($request, true);

        $contract->update($data);

        return response()->json(['data' => $contract->fresh()]);
    }

    public function destroy(Contract $contract): JsonResponse
    {
        $this->authorize('finance-config.manage');
        $contract->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function validateContract(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'party_type' => ['required', 'in:customer,vendor,insurer,other'],
            'party_id' => ['nullable', 'integer'],
            'party_name' => ['nullable', 'string', 'max:180'],
            'category' => ['required', 'in:commission,service,incentive,lease,other'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:250'],
            'status' => [$isUpdate ? 'sometimes' : 'prohibited', 'in:active,expired,terminated'],
        ]);
    }
}
