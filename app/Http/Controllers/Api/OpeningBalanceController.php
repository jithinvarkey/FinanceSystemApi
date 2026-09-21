<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOpeningBalanceRequest;
use App\Http\Requests\StoreOpeningPayableRequest;
use App\Http\Requests\StoreOpeningReceivableRequest;
use App\Http\Resources\CustomerInvoiceResource;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\VendorInvoiceResource;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\VendorInvoice;
use App\Services\OpeningBalanceService;
use App\Services\OpeningInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * P2.9 — Opening balances: load a cutover trial balance as a directly-posted
 * journal, plus the open AR/AP sub-ledger items (P2.9b). Gated on
 * general-ledger.post (a privileged setup action).
 */
final class OpeningBalanceController extends Controller
{
    public function __construct(
        private readonly OpeningBalanceService $service,
        private readonly OpeningInvoiceService $items,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.view');

        return JournalEntryResource::collection(
            JournalEntry::query()
                ->where('is_opening', true)
                ->with('lines.account')
                ->orderByDesc('journal_date')
                ->orderByDesc('id')
                ->limit(min((int) $request->integer('per_page', 50), 100))
                ->get(),
        );
    }

    public function store(StoreOpeningBalanceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $journal = $this->service->post(
            Carbon::parse($data['cutover_date']),
            $data['lines'],
            (int) $request->user()->id,
            isset($data['equity_account_id']) ? (int) $data['equity_account_id'] : null,
            $data['description'] ?? null,
        );

        return (new JournalEntryResource($journal))->response()->setStatusCode(201);
    }

    /** Open receivables brought in at cutover. */
    public function receivables(Request $request): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.view');

        return CustomerInvoiceResource::collection(
            CustomerInvoice::query()->where('is_opening', true)->with('customer')
                ->orderByDesc('invoice_date')->orderByDesc('id')->get(),
        );
    }

    public function storeReceivable(StoreOpeningReceivableRequest $request): JsonResponse
    {
        $invoice = $this->items->postOpeningReceivable($request->validated(), (int) $request->user()->id);

        return (new CustomerInvoiceResource($invoice))->response()->setStatusCode(201);
    }

    /** Open payables brought in at cutover. */
    public function payables(Request $request): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.view');

        return VendorInvoiceResource::collection(
            VendorInvoice::query()->where('is_opening', true)->with('vendor')
                ->orderByDesc('invoice_date')->orderByDesc('id')->get(),
        );
    }

    public function storePayable(StoreOpeningPayableRequest $request): JsonResponse
    {
        $invoice = $this->items->postOpeningPayable($request->validated(), (int) $request->user()->id);

        return (new VendorInvoiceResource($invoice))->response()->setStatusCode(201);
    }
}
