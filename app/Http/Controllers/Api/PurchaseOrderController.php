<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * N2 — Procurement-to-Pay: purchase orders, goods receipts, 3-way match.
 */
final class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('accounts-payable.view');

        $orders = PurchaseOrder::query()
            ->with(['lines', 'vendor:id,name,vendor_code'])
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->limit(100)->get();

        return response()->json(['data' => $orders]);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('accounts-payable.view');

        return response()->json(['data' => [
            'order' => $purchaseOrder->load(['lines', 'vendor:id,name', 'receipts.lines']),
            'match' => $this->service->threeWayMatch($purchaseOrder),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:250'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $po = $this->service->create(
            (int) $data['vendor_id'], Carbon::parse($data['order_date']),
            isset($data['expected_date']) ? Carbon::parse($data['expected_date']) : null,
            $data['notes'] ?? null, $data['lines'], (int) $request->user()->id,
        );

        return response()->json(['data' => $po], 201);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('accounts-payable.manage');

        return response()->json(['data' => $this->service->approve($purchaseOrder, (int) $request->user()->id)]);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('accounts-payable.manage');
        $data = $request->validate([
            'receipt_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:250'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $gr = $this->service->receive($purchaseOrder, Carbon::parse($data['receipt_date']), $data['note'] ?? null, $data['lines'], (int) $request->user()->id);

        return response()->json(['data' => $gr], 201);
    }
}
