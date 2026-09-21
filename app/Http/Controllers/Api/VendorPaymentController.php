<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVendorPaymentRequest;
use App\Http\Resources\VendorInvoiceResource;
use App\Http\Resources\VendorPaymentResource;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use App\Repositories\Contracts\VendorPaymentRepositoryInterface;
use App\Services\VendorPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P3.11–P3.15 — Vendor payment endpoints. Approval via the approvals inbox;
 * posting (Dr AP → Cr bank) through GlPostingService.
 */
final class VendorPaymentController extends Controller
{
    public function __construct(
        private readonly VendorPaymentRepositoryInterface $payments,
        private readonly VendorPaymentService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-payable.view');

        return VendorPaymentResource::collection(
            $this->payments->paginate(
                filters: array_merge(
                    $request->only(['status', 'vendor_id']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['vendor', 'bankAccount'],
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    /** P3.11 — posted invoices of a vendor that still owe money (payment proposal). */
    public function payableInvoices(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-payable.view');

        $vendorId = (int) $request->integer('vendor_id');

        $invoices = VendorInvoice::query()
            ->where('vendor_id', $vendorId)
            ->where('status', 'posted')
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->orderBy('due_date')
            ->get();

        return VendorInvoiceResource::collection($invoices);
    }

    public function store(StoreVendorPaymentRequest $request): JsonResponse
    {
        $payment = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new VendorPaymentResource($payment))->response()->setStatusCode(201);
    }

    public function show(VendorPayment $vendorPayment): VendorPaymentResource
    {
        $this->authorize('accounts-payable.view');

        return new VendorPaymentResource($vendorPayment->load(['vendor', 'bankAccount', 'allocations.invoice']));
    }

    public function destroy(VendorPayment $vendorPayment): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $this->service->deleteDraft($vendorPayment);

        return response()->json(null, 204);
    }

    public function update(StoreVendorPaymentRequest $request, VendorPayment $vendorPayment): VendorPaymentResource
    {
        return new VendorPaymentResource($this->service->updateDraft($vendorPayment, $request->validated()));
    }

    public function submit(Request $request, VendorPayment $vendorPayment): VendorPaymentResource
    {
        $this->authorize('accounts-payable.manage');

        return new VendorPaymentResource($this->service->submit($vendorPayment, (int) $request->user()->id));
    }

    public function post(Request $request, VendorPayment $vendorPayment): VendorPaymentResource
    {
        $this->authorize('accounts-payable.post');

        return new VendorPaymentResource($this->service->post($vendorPayment, (int) $request->user()->id));
    }
}
