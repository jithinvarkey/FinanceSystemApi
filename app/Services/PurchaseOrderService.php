<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\VendorInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * N2 — Procurement-to-Pay. A draft PO (the requisition) is approved, received
 * against, then 3-way matched (ordered vs received vs invoiced).
 */
final class PurchaseOrderService
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    /**
     * @param  list<array{description: string, quantity: float, unit_price: float}>  $lines
     */
    public function create(int $vendorId, Carbon $orderDate, ?Carbon $expected, ?string $notes, array $lines, int $userId): PurchaseOrder
    {
        if ($lines === []) {
            throw new FinanceRuleException('A purchase order needs at least one line.');
        }

        return DB::transaction(function () use ($vendorId, $orderDate, $expected, $notes, $lines, $userId): PurchaseOrder {
            $po = PurchaseOrder::query()->create([
                'po_number' => $this->numbers->next('purchase_order', $orderDate),
                'vendor_id' => $vendorId, 'order_date' => $orderDate->toDateString(),
                'expected_date' => $expected?->toDateString(), 'total_amount' => 0,
                'status' => 'draft', 'notes' => $notes, 'created_by' => $userId,
            ]);

            $total = 0.0;
            foreach ($lines as $line) {
                $lineTotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $total = round($total + $lineTotal, 2);
                $po->lines()->create([
                    'description' => $line['description'], 'quantity' => round((float) $line['quantity'], 2),
                    'unit_price' => round((float) $line['unit_price'], 2), 'line_total' => $lineTotal, 'received_qty' => 0,
                ]);
            }
            $po->update(['total_amount' => $total]);

            return $po->load('lines');
        });
    }

    public function approve(PurchaseOrder $po, int $userId): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw new FinanceRuleException('Only a draft purchase order can be approved.');
        }
        $po->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);

        return $po->fresh(['lines']);
    }

    /**
     * Record a goods receipt against an approved PO.
     *
     * @param  list<array{purchase_order_line_id: int, quantity: float}>  $lines
     */
    public function receive(PurchaseOrder $po, Carbon $date, ?string $note, array $lines, int $userId): GoodsReceipt
    {
        if (! in_array($po->status, ['approved', 'partially_received'], true)) {
            throw new FinanceRuleException('Goods can only be received against an approved purchase order.');
        }
        if ($lines === []) {
            throw new FinanceRuleException('Nothing to receive.');
        }

        return DB::transaction(function () use ($po, $date, $note, $lines, $userId): GoodsReceipt {
            $gr = GoodsReceipt::query()->create([
                'gr_number' => $this->numbers->next('goods_receipt', $date),
                'purchase_order_id' => $po->id, 'receipt_date' => $date->toDateString(), 'note' => $note, 'received_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $qty = round((float) $line['quantity'], 2);
                if ($qty <= 0) {
                    continue;
                }
                /** @var PurchaseOrderLine $poLine */
                $poLine = $po->lines()->whereKey($line['purchase_order_line_id'])->firstOrFail();
                if ($qty > $poLine->outstandingQty() + 0.01) {
                    throw new FinanceRuleException("Receipt for \"{$poLine->description}\" exceeds the outstanding ordered quantity.");
                }
                $gr->lines()->create(['purchase_order_line_id' => $poLine->id, 'quantity' => $qty]);
                $poLine->update(['received_qty' => round((float) $poLine->received_qty + $qty, 2)]);
            }

            $po->update(['status' => $this->isFullyReceived($po->fresh('lines')) ? 'received' : 'partially_received']);

            return $gr->load('lines');
        });
    }

    /**
     * 3-way match: ordered vs received vs invoiced.
     *
     * @return array{ordered: float, received: float, invoiced: float, over_received: bool, over_invoiced: bool, fully_received: bool, matched: bool}
     */
    public function threeWayMatch(PurchaseOrder $po): array
    {
        $po->loadMissing('lines');
        $ordered = round((float) $po->lines->sum('line_total'), 2);
        $received = round((float) $po->lines->sum(fn (PurchaseOrderLine $l): float => (float) $l->received_qty * (float) $l->unit_price), 2);
        $invoiced = round((float) VendorInvoice::query()
            ->where('purchase_order_id', $po->id)
            ->whereIn('status', ['approved', 'posted'])
            ->sum('total_amount'), 2);

        $fullyReceived = $this->isFullyReceived($po);
        $overReceived = $received > $ordered + 0.01;
        $overInvoiced = $invoiced > $received + 0.01;

        return [
            'ordered' => $ordered, 'received' => $received, 'invoiced' => $invoiced,
            'over_received' => $overReceived, 'over_invoiced' => $overInvoiced,
            'fully_received' => $fullyReceived,
            'matched' => $fullyReceived && ! $overInvoiced && abs($invoiced - $received) < 0.01,
        ];
    }

    private function isFullyReceived(PurchaseOrder $po): bool
    {
        return $po->lines->every(fn (PurchaseOrderLine $l): bool => (float) $l->received_qty >= (float) $l->quantity - 0.01);
    }
}
