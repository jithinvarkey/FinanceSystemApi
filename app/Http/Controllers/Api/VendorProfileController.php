<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\VendorProfileService;
use Illuminate\Http\JsonResponse;

/**
 * E11 — Vendor self-service portal (internal): consolidated vendor financial view.
 */
final class VendorProfileController extends Controller
{
    public function __construct(private readonly VendorProfileService $service)
    {
    }

    public function profile(Vendor $vendor): JsonResponse
    {
        $this->authorize('accounts-payable.view');

        return response()->json(['data' => $this->service->profile($vendor)]);
    }
}
