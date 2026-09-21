<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F16 — Company/seller identity (single row): the legal name, VAT registration
 * number and address used for ZATCA e-invoices and document headers.
 */
final class CompanySettingController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorize('finance-config.view');

        return response()->json(['data' => CompanySetting::current()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('finance-config.manage');

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:180'],
            'vat_number' => ['nullable', 'string', 'size:15'],
            'commercial_reg_no' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        $settings = CompanySetting::current();
        $settings->update($data);

        return response()->json(['data' => $settings->fresh()]);
    }
}
