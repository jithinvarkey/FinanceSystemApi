<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E2 — Customer 360 financial view + collection activity log.
 */
final class CustomerProfileController extends Controller
{
    public function __construct(private readonly CustomerProfileService $service)
    {
    }

    public function profile(Customer $customer): JsonResponse
    {
        $this->authorize('accounts-receivable.view');

        return response()->json(['data' => $this->service->profile($customer)]);
    }

    public function addActivity(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('accounts-receivable.manage');

        $data = $request->validate([
            'activity_type' => ['required', 'in:note,call,email,meeting'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $this->service->addActivity($customer, $data['activity_type'], $data['note'], (int) $request->user()->id);

        return response()->json(['data' => $this->service->activities($customer)], 201);
    }
}
