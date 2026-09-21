<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVendorInvoiceRequest;
use App\Http\Resources\VendorInvoiceResource;
use App\Models\VendorInvoice;
use App\Repositories\Contracts\VendorInvoiceRepositoryInterface;
use App\Services\VendorInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P3.6–P3.10 — Vendor invoice endpoints. Approval runs through the generic
 * approvals inbox (P0.4); posting writes to the GL through GlPostingService.
 */
final class VendorInvoiceController extends Controller
{
    public function __construct(
        private readonly VendorInvoiceRepositoryInterface $invoices,
        private readonly VendorInvoiceService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-payable.view');

        return VendorInvoiceResource::collection(
            $this->invoices->paginate(
                filters: array_merge(
                    $request->only(['status', 'vendor_id']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['vendor'],
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    public function store(StoreVendorInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new VendorInvoiceResource($invoice->load('vendor')))->response()->setStatusCode(201);
    }

    public function show(VendorInvoice $vendorInvoice): VendorInvoiceResource
    {
        $this->authorize('accounts-payable.view');

        return new VendorInvoiceResource($vendorInvoice->load(['vendor', 'lines.account']));
    }

    public function update(StoreVendorInvoiceRequest $request, VendorInvoice $vendorInvoice): VendorInvoiceResource
    {
        return new VendorInvoiceResource(
            $this->service->updateDraft($vendorInvoice, $request->validated())->load('vendor'),
        );
    }

    public function destroy(VendorInvoice $vendorInvoice): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $this->service->deleteDraft($vendorInvoice);

        return response()->json(null, 204);
    }

    public function submit(Request $request, VendorInvoice $vendorInvoice): VendorInvoiceResource
    {
        $this->authorize('accounts-payable.manage');

        return new VendorInvoiceResource($this->service->submit($vendorInvoice, (int) $request->user()->id)->load('vendor'));
    }

    public function post(Request $request, VendorInvoice $vendorInvoice): VendorInvoiceResource
    {
        $this->authorize('accounts-payable.post');

        return new VendorInvoiceResource($this->service->post($vendorInvoice, (int) $request->user()->id)->load(['vendor', 'lines.account']));
    }

    /** Take the vendor's early-payment settlement discount. */
    public function takeDiscount(Request $request, VendorInvoice $vendorInvoice): VendorInvoiceResource
    {
        $this->authorize('accounts-payable.post');
        $data = $request->validate(['discount_income_account_id' => ['required', 'exists:chart_of_accounts,id']]);

        $this->service->takeEarlyPaymentDiscount($vendorInvoice, (int) $data['discount_income_account_id'], (int) $request->user()->id);

        return new VendorInvoiceResource($vendorInvoice->fresh(['vendor', 'lines.account']));
    }

    /** Apply withholding tax to a posted bill. */
    public function withhold(Request $request, VendorInvoice $vendorInvoice): VendorInvoiceResource
    {
        $this->authorize('accounts-payable.post');
        $data = $request->validate(['wht_payable_account_id' => ['required', 'exists:chart_of_accounts,id']]);

        $this->service->applyWithholding($vendorInvoice, (int) $data['wht_payable_account_id'], (int) $request->user()->id);

        return new VendorInvoiceResource($vendorInvoice->fresh(['vendor', 'lines.account']));
    }

    /** Withholding-tax report (bills with WHT applied). */
    public function withholdingReport(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('accounts-payable.view');
        $from = $request->filled('from') ? \Illuminate\Support\Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? \Illuminate\Support\Carbon::parse($request->string('to')->toString()) : null;

        return response()->json(['data' => $this->service->withholdingReport($from, $to)]);
    }
}
