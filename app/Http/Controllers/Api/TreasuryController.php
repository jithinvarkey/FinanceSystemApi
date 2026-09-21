<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TreasuryItem;
use App\Services\TreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E7 — Treasury: cash position, cash-flow forecast and planned cash movements.
 */
final class TreasuryController extends Controller
{
    public function __construct(private readonly TreasuryService $service)
    {
    }

    public function position(): JsonResponse
    {
        $this->authorize('banking.view');

        return response()->json(['data' => $this->service->cashPositions()]);
    }

    public function forecast(Request $request): JsonResponse
    {
        $this->authorize('banking.view');
        $weeks = (int) $request->integer('weeks', 12);

        return response()->json(['data' => $this->service->forecast($weeks)]);
    }

    public function items(): JsonResponse
    {
        $this->authorize('banking.view');

        return response()->json(['data' => TreasuryItem::query()->where('status', 'planned')->orderBy('expected_date')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('banking.manage');
        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'direction' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expected_date' => ['required', 'date'],
        ]);

        $item = TreasuryItem::query()->create([...$data, 'status' => 'planned', 'created_by' => (int) $request->user()->id]);

        return response()->json(['data' => $item], 201);
    }

    public function destroy(TreasuryItem $treasuryItem): JsonResponse
    {
        $this->authorize('banking.manage');
        $treasuryItem->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
