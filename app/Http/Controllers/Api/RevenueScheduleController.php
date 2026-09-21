<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RevenueSchedule;
use App\Services\RevenueRecognitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * E6 — Revenue recognition (IFRS 15) schedules.
 */
final class RevenueScheduleController extends Controller
{
    public function __construct(private readonly RevenueRecognitionService $service)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => RevenueSchedule::query()->with('lines')->latest('id')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'deferred_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'revenue_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $schedule = $this->service->createSchedule(
            $data['name'], (float) $data['total_amount'],
            (int) $data['deferred_account_id'], (int) $data['revenue_account_id'],
            Carbon::parse($data['start_date']), Carbon::parse($data['end_date']),
            (int) $request->user()->id,
        );

        return response()->json(['data' => $schedule], 201);
    }

    public function recognize(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate(['as_of' => ['required', 'date']]);

        $result = $this->service->recognizeDue(Carbon::parse($data['as_of']), (int) $request->user()->id);

        return response()->json(['data' => $result]);
    }
}
