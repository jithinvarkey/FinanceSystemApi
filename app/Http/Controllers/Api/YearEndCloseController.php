<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JournalEntryResource;
use App\Models\FiscalYear;
use App\Services\YearEndCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P10 — Year-end close. Preview the net result, then post the closing journal
 * and lock the year. Gated on general-ledger.post (privileged).
 */
final class YearEndCloseController extends Controller
{
    public function __construct(private readonly YearEndCloseService $service)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $data = $request->validate(['fiscal_year_id' => ['required', 'exists:fiscal_years,id']]);

        $year = FiscalYear::query()->findOrFail($data['fiscal_year_id']);

        return response()->json(['data' => $this->service->preview($year)]);
    }

    public function close(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'retained_earnings_account_id' => ['required', 'exists:chart_of_accounts,id'],
        ]);

        $year = FiscalYear::query()->findOrFail($data['fiscal_year_id']);
        $journal = $this->service->close($year, (int) $data['retained_earnings_account_id'], (int) $request->user()->id);

        return (new JournalEntryResource($journal))->response()->setStatusCode(201);
    }
}
