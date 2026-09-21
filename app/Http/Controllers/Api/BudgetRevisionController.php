<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Services\BudgetRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E9 — Budget revisions & transfers.
 */
final class BudgetRevisionController extends Controller
{
    public function __construct(private readonly BudgetRevisionService $service)
    {
    }

    public function history(Budget $budget): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => $this->service->history($budget)]);
    }

    public function revise(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'budget_line_id' => ['required', 'integer'],
            'new_amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        $line = $this->lineForBudget($budget, (int) $data['budget_line_id']);
        $revision = $this->service->revise($line, (float) $data['new_amount'], $data['reason'], (int) $request->user()->id);

        return response()->json(['data' => $revision], 201);
    }

    public function transfer(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'from_line_id' => ['required', 'integer'],
            'to_line_id' => ['required', 'integer', 'different:from_line_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        $from = $this->lineForBudget($budget, (int) $data['from_line_id']);
        $to = $this->lineForBudget($budget, (int) $data['to_line_id']);
        $rows = $this->service->transfer($from, $to, (float) $data['amount'], $data['reason'], (int) $request->user()->id);

        return response()->json(['data' => $rows], 201);
    }

    private function lineForBudget(Budget $budget, int $lineId): BudgetLine
    {
        return BudgetLine::query()->where('budget_id', $budget->id)->whereKey($lineId)->firstOrFail();
    }
}
