<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReceiptRequest;
use App\Http\Resources\CustomerInvoiceResource;
use App\Http\Resources\ReceiptResource;
use App\Models\CustomerInvoice;
use App\Models\Receipt;
use App\Repositories\Contracts\ReceiptRepositoryInterface;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.11–P4.14 — Customer receipt endpoints. Approval via the approvals inbox;
 * posting (Dr bank → Cr AR) through GlPostingService.
 */
final class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptRepositoryInterface $receipts,
        private readonly ReceiptService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-receivable.view');

        return ReceiptResource::collection(
            $this->receipts->paginate(
                filters: array_merge(
                    $request->only(['status', 'customer_id']),
                    ['search' => $request->string('search')->toString() ?: null],
                ),
                with: ['customer', 'bankAccount'],
                perPage: min((int) $request->integer('per_page', 25), 100),
            ),
        );
    }

    /** P4.11 — posted invoices of a customer that still owe money (receipt proposal). */
    public function receivableInvoices(Request $request): AnonymousResourceCollection
    {
        $this->authorize('accounts-receivable.view');

        $customerId = (int) $request->integer('customer_id');

        $invoices = CustomerInvoice::query()
            ->where('customer_id', $customerId)
            ->where('status', 'posted')
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->orderBy('due_date')
            ->get();

        return CustomerInvoiceResource::collection($invoices);
    }

    public function store(StoreReceiptRequest $request): JsonResponse
    {
        $receipt = $this->service->createDraft($request->validated(), (int) $request->user()->id);

        return (new ReceiptResource($receipt))->response()->setStatusCode(201);
    }

    public function show(Receipt $receipt): ReceiptResource
    {
        $this->authorize('accounts-receivable.view');

        return new ReceiptResource($receipt->load(['customer', 'bankAccount', 'allocations.invoice']));
    }

    public function destroy(Receipt $receipt): JsonResponse
    {
        $this->authorize('accounts-receivable.manage');
        $this->service->deleteDraft($receipt);

        return response()->json(null, 204);
    }

    public function update(StoreReceiptRequest $request, Receipt $receipt): ReceiptResource
    {
        return new ReceiptResource($this->service->updateDraft($receipt, $request->validated()));
    }

    public function submit(Request $request, Receipt $receipt): ReceiptResource
    {
        $this->authorize('accounts-receivable.manage');

        return new ReceiptResource($this->service->submit($receipt, (int) $request->user()->id));
    }

    public function post(Request $request, Receipt $receipt): ReceiptResource
    {
        $this->authorize('accounts-receivable.post');

        return new ReceiptResource($this->service->post($receipt, (int) $request->user()->id));
    }
}
