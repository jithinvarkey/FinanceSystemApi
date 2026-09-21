<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WorkforceBudgetService;
use App\Services\WorkforceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * W4/W8 — workforce cost analytics + budget vs actual.
 */
final class WorkforceController extends Controller
{
    public function __construct(
        private readonly WorkforceReportService $service,
        private readonly WorkforceBudgetService $budgets,
    ) {
    }

    public function analytics(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $year = (int) ($request->integer('year') ?: (int) now()->format('Y'));

        return response()->json(['data' => $this->service->analytics($year)]);
    }

    public function budget(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $year = (int) ($request->integer('year') ?: (int) now()->format('Y'));

        return response()->json(['data' => $this->budgets->comparison($year)]);
    }

    public function setBudget(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'budgets' => ['required', 'array'],
        ]);

        $this->budgets->setBudgets((int) $data['year'], $data['budgets']);

        return response()->json(['data' => $this->budgets->comparison((int) $data['year'])]);
    }
}
