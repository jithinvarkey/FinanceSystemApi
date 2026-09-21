<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRecurringInvoiceRequest;
use App\Http\Resources\RecurringInvoiceResource;
use App\Models\RecurringInvoice;
use App\Services\RecurringInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Recurring invoices/bills. Gated on general-ledger (the generated drafts then
 * follow the normal AR/AP approval flow).
 */
final class RecurringInvoiceController extends Controller
{
    public function __construct(private readonly RecurringInvoiceService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.view');

        $rows = RecurringInvoice::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest('id')->get();

        return RecurringInvoiceResource::collection($rows);
    }

    public function store(StoreRecurringInvoiceRequest $request): JsonResponse
    {
        $template = $this->service->save($request->validated(), (int) $request->user()->id);

        return (new RecurringInvoiceResource($template))->response()->setStatusCode(201);
    }

    public function update(StoreRecurringInvoiceRequest $request, RecurringInvoice $recurringInvoice): RecurringInvoiceResource
    {
        return new RecurringInvoiceResource($this->service->save($request->validated(), (int) $request->user()->id, $recurringInvoice));
    }

    public function pause(Request $request, RecurringInvoice $recurringInvoice): RecurringInvoiceResource
    {
        $this->authorize('general-ledger.manage');
        $recurringInvoice->update(['is_active' => false]);

        return new RecurringInvoiceResource($recurringInvoice);
    }

    public function resume(Request $request, RecurringInvoice $recurringInvoice): RecurringInvoiceResource
    {
        $this->authorize('general-ledger.manage');
        $recurringInvoice->update(['is_active' => true]);

        return new RecurringInvoiceResource($recurringInvoice);
    }

    /** Generate the next draft from one template now. */
    public function generate(Request $request, RecurringInvoice $recurringInvoice): JsonResponse
    {
        $this->authorize('general-ledger.manage');
        $number = $this->service->generateOne($recurringInvoice, (int) $request->user()->id);

        return response()->json(['data' => ['document' => $number]]);
    }

    /** Generate every template due on/before a date. */
    public function generateDue(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.manage');
        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = isset($data['as_of']) ? Carbon::parse($data['as_of']) : Carbon::today();

        return response()->json(['data' => $this->service->generateDue($asOf, (int) $request->user()->id)]);
    }
}
