<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryGrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * W5 — salary grade catalog.
 */
final class SalaryGradeController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        return response()->json(['data' => SalaryGrade::query()->orderBy('code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $this->validateGrade($request);
        $data['code'] = strtoupper($data['code']);

        return response()->json(['data' => SalaryGrade::query()->create([...$data, 'status' => 'active'])], 201);
    }

    public function update(Request $request, SalaryGrade $salaryGrade): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $salaryGrade->update($this->validateGrade($request, $salaryGrade->id));

        return response()->json(['data' => $salaryGrade->fresh()]);
    }

    /** @return array<string, mixed> */
    private function validateGrade(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:salary_grades,code'.($ignoreId ? ','.$ignoreId : '')],
            'name' => ['required', 'string', 'max:120'],
            'min_salary' => ['required', 'numeric', 'min:0'],
            'mid_salary' => ['required', 'numeric', 'min:0'],
            'max_salary' => ['required', 'numeric', 'gte:min_salary'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
    }
}
