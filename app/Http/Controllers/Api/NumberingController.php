<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NumberSequence;
use App\Services\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * F21 — Document numbering administration. Lists every numbering scheme (from
 * config/numbering.php) alongside its live counter, and lets an admin adjust the
 * prefix / padding / next number for the current period.
 */
final class NumberingController extends Controller
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    public function index(): JsonResponse
    {
        $this->authorize('finance-config.view');

        $types = config('numbering.types', []);
        $year = (int) now()->year;

        $rows = [];
        foreach ($types as $type => $cfg) {
            $period = $cfg['yearly'] ? $year : 0;
            $seq = NumberSequence::query()
                ->where('document_type', $type)->where('branch_id', 0)->where('period_year', $period)
                ->first();

            $rows[] = [
                'document_type' => $type,
                'label' => Str::headline($type),
                'prefix' => $seq?->prefix ?? $cfg['prefix'],
                'padding' => $seq?->padding ?? $cfg['padding'],
                'yearly' => (bool) $cfg['yearly'],
                'period_year' => $period,
                'next_number' => $seq?->next_number ?? 1,
                'next_preview' => $this->numbers->peek($type, now()),
                'sequence_id' => $seq?->id,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:60'],
            'period_year' => ['required', 'integer', 'min:0'],
            'next_number' => ['required', 'integer', 'min:1'],
            'prefix' => ['required', 'string', 'max:12'],
            'padding' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $cfg = config("numbering.types.{$data['document_type']}");
        abort_if($cfg === null, 422, 'Unknown document type.');

        $seq = NumberSequence::query()->updateOrCreate(
            ['document_type' => $data['document_type'], 'branch_id' => 0, 'period_year' => $data['period_year']],
            [
                'prefix' => $data['prefix'],
                'padding' => $data['padding'],
                'include_year' => (bool) $cfg['yearly'],
                'separator' => (string) config('numbering.separator', '-'),
                'next_number' => $data['next_number'],
            ],
        );

        return response()->json(['data' => [
            'document_type' => $seq->document_type,
            'next_number' => $seq->next_number,
            'next_preview' => $this->numbers->peek($seq->document_type, Carbon::create((int) ($seq->period_year ?: now()->year))),
        ]]);
    }
}
