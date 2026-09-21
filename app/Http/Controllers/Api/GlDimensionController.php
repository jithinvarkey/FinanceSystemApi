<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GlDimension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * F27 — GL analysis dimensions: CRUD master + a dimension-analysis report
 * (net movement per dimension over a period).
 */
final class GlDimensionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('finance-config.view');

        $dims = GlDimension::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderBy('type')->orderBy('code')->get();

        return response()->json(['data' => $dims]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate([
            'type' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'max:30', Rule::unique('gl_dimensions')->where('type', $request->input('type'))],
            'name' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        return response()->json(['data' => GlDimension::query()->create($data)], 201);
    }

    public function update(Request $request, GlDimension $glDimension): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $glDimension->update($data);

        return response()->json(['data' => $glDimension->fresh()]);
    }

    /** Net GL movement per dimension over a date range. */
    public function analysis(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : Carbon::today();

        $rows = DB::table('gl_transactions as g')
            ->join('gl_dimensions as d', 'd.id', '=', 'g.dimension_id')
            ->when($from !== null, fn ($q) => $q->whereDate('g.transaction_date', '>=', $from->toDateString()))
            ->whereDate('g.transaction_date', '<=', $to->toDateString())
            ->groupBy('d.id', 'd.type', 'd.code', 'd.name')
            ->selectRaw('d.type, d.code, d.name, SUM(g.base_debit - g.base_credit) as net')
            ->orderBy('d.type')->orderBy('d.code')
            ->get()
            ->map(fn ($r): array => ['type' => $r->type, 'code' => $r->code, 'name' => $r->name, 'net' => round((float) $r->net, 2)]);

        return response()->json(['data' => $rows]);
    }
}
