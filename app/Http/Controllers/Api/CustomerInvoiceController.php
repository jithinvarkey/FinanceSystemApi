<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerInvoiceRequest;
use App\Http\Resources\CustomerInvoiceResource;
use App\Models\CustomerInvoice;
use App\Repositories\Contracts\CustomerInvoiceRepositoryInterface;
use App\Services\CustomerInvoiceService;
use App\Services\Tax\ZatcaQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.6–P4.10 — Customer invoice endpoints. Approval runs through the generic
 * approvals inbox (P0.4); posting writes to the GL through GlPostingService.
 */
final class CustomerInvoiceController extends Controller
{
    public function __construct(
        private readonly CustomerInvoiceRepositoryInterface $invoices,
        private readonly CustomerInvoiceService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-receivable.view');

        return CustomerInvoiceResource::collection(
            $this->invoices->paginate(
                filters: array_merge(
                    $request->only(['status', 'customer_id']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['customer'],
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    public function store(StoreCustomerInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new CustomerInvoiceResource($invoice->load('customer')))->response()->setStatusCode(201);
    }

    public function show(CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        $this->authorize('accounts-receivable.view');

        return new CustomerInvoiceResource($customerInvoice->load(['customer', 'lines.account']));
    }

    public function update(StoreCustomerInvoiceRequest $request, CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        return new CustomerInvoiceResource(
            $this->service->updateDraft($customerInvoice, $request->validated())->load('customer'),
        );
    }

    public function destroy(CustomerInvoice $customerInvoice): JsonResponse
    {
        $this->authorize('accounts-receivable.manage');
        $this->service->deleteDraft($customerInvoice);

        return response()->json(null, 204);
    }

    public function submit(Request $request, CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        $this->authorize('accounts-receivable.manage');

        return new CustomerInvoiceResource($this->service->submit($customerInvoice, (int) $request->user()->id)->load('customer'));
    }

    public function post(Request $request, CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        $this->authorize('accounts-receivable.post');

        return new CustomerInvoiceResource($this->service->post($customerInvoice, (int) $request->user()->id)->load(['customer', 'lines.account']));
    }

    /** Write off the outstanding balance as a bad debt. */
    public function writeOff(Request $request, CustomerInvoice $customerInvoice): CustomerInvoiceResource
    {
        $this->authorize('accounts-receivable.post');
        $data = $request->validate(['bad_debt_account_id' => ['required', 'exists:chart_of_accounts,id']]);

        $invoice = $this->service->writeOff($customerInvoice, (int) $data['bad_debt_account_id'], (int) $request->user()->id);

        return new CustomerInvoiceResource($invoice->load(['customer', 'lines.account']));
    }

    /**
     * F16 — ZATCA Fatoorah Phase-1 QR payload (TLV/Base64) for a posted invoice.
     */
    public function zatcaQr(CustomerInvoice $customerInvoice, ZatcaQrService $qr): JsonResponse
    {
        $this->authorize('accounts-receivable.view');

        if ($customerInvoice->status->value !== 'posted') {
            return response()->json(['message' => 'A ZATCA QR is only issued for a posted tax invoice.'], 422);
        }

        return response()->json(['data' => $qr->forCustomerInvoice($customerInvoice)]);
    }
}
