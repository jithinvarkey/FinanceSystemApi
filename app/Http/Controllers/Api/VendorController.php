<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlockVendorRequest;
use App\Http\Requests\StoreVendorBankAccountRequest;
use App\Http\Requests\StoreVendorRequest;
use App\Http\Resources\VendorBankAccountResource;
use App\Http\Resources\VendorResource;
use App\Models\Vendor;
use App\Models\VendorBankAccount;
use App\Repositories\Contracts\VendorRepositoryInterface;
use App\Services\VendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P3.1–P3.5 — Vendor master endpoints. Approval itself happens through the
 * generic approvals inbox (P0.4); submitting a vendor raises that request.
 */
final class VendorController extends Controller
{
    public function __construct(
        private readonly VendorRepositoryInterface $vendors,
        private readonly VendorService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-payable.view');

        return VendorResource::collection(
            $this->vendors->paginate(
                filters: array_merge(
                    $request->only(['status', 'vendor_type', 'is_blocked']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    /** P3.3 — vendors whose trade licence expires soon (compliance alert). */
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-payable.view');

        $days = min((int) $request->integer('days', 30), 365);

        return VendorResource::collection(
            Vendor::query()->licenceExpiringWithin($days)->orderBy('trade_license_expiry')->get(),
        );
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function show(Vendor $vendor): VendorResource
    {
        $this->authorize('accounts-payable.view');

        return new VendorResource($vendor->load('bankAccounts'));
    }

    public function update(StoreVendorRequest $request, Vendor $vendor): VendorResource
    {
        return new VendorResource($this->service->updateDraft($vendor, $request->validated()));
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $this->service->deleteDraft($vendor);

        return response()->json(null, 204);
    }

    public function submit(Request $request, Vendor $vendor): VendorResource
    {
        $this->authorize('accounts-payable.manage');

        return new VendorResource($this->service->submit($vendor, (int) $request->user()->id));
    }

    public function block(BlockVendorRequest $request, Vendor $vendor): VendorResource
    {
        return new VendorResource(
            $this->service->block($vendor, (int) $request->user()->id, $request->string('reason')->toString()),
        );
    }

    public function unblock(Vendor $vendor): VendorResource
    {
        $this->authorize('accounts-payable.manage');

        return new VendorResource($this->service->unblock($vendor));
    }

    // ----- Bank accounts (P3.4) -----

    public function addBankAccount(StoreVendorBankAccountRequest $request, Vendor $vendor): JsonResponse
    {
        $account = $this->service->addBankAccount($vendor, $request->validated());

        return (new VendorBankAccountResource($account))->response()->setStatusCode(201);
    }

    public function updateBankAccount(StoreVendorBankAccountRequest $request, VendorBankAccount $bankAccount): VendorBankAccountResource
    {
        return new VendorBankAccountResource($this->service->updateBankAccount($bankAccount, $request->validated()));
    }

    public function verifyBankAccount(VendorBankAccount $bankAccount): VendorBankAccountResource
    {
        $this->authorize('accounts-payable.approve');

        return new VendorBankAccountResource($this->service->verifyBankAccount($bankAccount));
    }

    public function deleteBankAccount(VendorBankAccount $bankAccount): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $bankAccount->delete();

        return response()->json(null, 204);
    }
}
