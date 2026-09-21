<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * P3.17 — Accounts Payable reporting: vendor ledger (statement) and AP aging.
 *
 * Read-only. Only POSTED documents touch the ledger, so both reports look at
 * posted vendor invoices (increase what we owe) and posted vendor payments
 * (reduce it). Money is held in SAR to 2 dp, consistent with the GL.
 */
final class AccountsPayableReportService
{
    /** Aging bucket boundaries, in days past due. */
    private const BUCKETS = ['current', '1_30', '31_60', '61_90', '91_120', '120_plus'];

    /**
     * P3.17 — AP aging as of a date.
     *
     * For every posted invoice still owing money on $asOf, the outstanding
     * amount is placed in a bucket by how far past its reference date it is
     * ($basis = 'due_date' default, or 'invoice_date'). Returns one row per
     * vendor with a balance, plus grand totals.
     *
     * @return array{
     *     as_of: string,
     *     basis: string,
     *     buckets: list<string>,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, float>
     * }
     */
    public function aging(?Carbon $asOf = null, string $basis = 'due_date'): array
    {
        $asOf = ($asOf ?? Carbon::today())->startOfDay();
        $basis = $basis === 'invoice_date' ? 'invoice_date' : 'due_date';

        $invoices = VendorInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '<=', $asOf->toDateString())
            ->with('vendor:id,name,vendor_code')
            ->get();

        $paidAsOf = $this->paidAsOf($invoices->pluck('id')->all(), $asOf);

        /** @var Collection<int, array<string, mixed>> $byVendor */
        $byVendor = collect();

        foreach ($invoices as $invoice) {
            $outstanding = round(
                (float) $invoice->total_amount - ($paidAsOf[$invoice->id] ?? 0.0),
                2,
            );

            if ($outstanding <= 0) {
                continue;
            }

            $reference = $basis === 'invoice_date'
                ? $invoice->invoice_date
                : ($invoice->due_date ?? $invoice->invoice_date);

            // Positive = days past the reference date on $asOf.
            $daysPast = (int) $reference->startOfDay()->diffInDays($asOf, false);
            $bucket = $this->bucketFor($daysPast);

            $vendorId = (int) $invoice->vendor_id;
            $row = $byVendor->get($vendorId) ?? $this->emptyVendorRow($invoice->vendor);

            $row[$bucket] = round($row[$bucket] + $outstanding, 2);
            $row['total'] = round($row['total'] + $outstanding, 2);
            $byVendor->put($vendorId, $row);
        }

        $rows = $byVendor->values()
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'as_of' => $asOf->toDateString(),
            'basis' => $basis,
            'buckets' => self::BUCKETS,
            'rows' => $rows,
            'totals' => $this->columnTotals($rows),
        ];
    }

    /**
     * P3.17 — Vendor statement: chronological posted transactions with a
     * running balance, plus the opening balance carried in from before $from.
     *
     * @return array{
     *     vendor: array<string, mixed>,
     *     from: ?string,
     *     to: string,
     *     opening_balance: float,
     *     entries: list<array<string, mixed>>,
     *     closing_balance: float
     * }
     */
    public function vendorLedger(Vendor $vendor, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = ($to ?? Carbon::today())->endOfDay();
        $from = $from?->startOfDay();

        $opening = $from !== null
            ? $this->balanceBefore($vendor->id, $from)
            : 0.0;

        $invoices = VendorInvoice::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', 'posted')
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->when($from !== null, fn ($q) => $q->whereDate('invoice_date', '>=', $from->toDateString()))
            ->get(['id', 'invoice_number', 'invoice_date', 'reference', 'description', 'total_amount']);

        $payments = VendorPayment::query()
            ->where('vendor_id', $vendor->id)
            ->where('status', 'posted')
            ->whereDate('payment_date', '<=', $to->toDateString())
            ->when($from !== null, fn ($q) => $q->whereDate('payment_date', '>=', $from->toDateString()))
            ->get(['id', 'payment_number', 'payment_date', 'reference', 'amount']);

        $entries = collect();

        foreach ($invoices as $invoice) {
            $entries->push([
                'sort' => $invoice->invoice_date->toDateString().'-1-'.str_pad((string) $invoice->id, 9, '0', STR_PAD_LEFT),
                'date' => $invoice->invoice_date->toDateString(),
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'description' => $invoice->description ?? $invoice->reference,
                'charge' => round((float) $invoice->total_amount, 2),
                'payment' => 0.0,
            ]);
        }

        foreach ($payments as $payment) {
            $entries->push([
                'sort' => $payment->payment_date->toDateString().'-2-'.str_pad((string) $payment->id, 9, '0', STR_PAD_LEFT),
                'date' => $payment->payment_date->toDateString(),
                'type' => 'payment',
                'reference' => $payment->payment_number,
                'description' => $payment->reference,
                'charge' => 0.0,
                'payment' => round((float) $payment->amount, 2),
            ]);
        }

        $balance = $opening;
        $ordered = $entries->sortBy('sort')->values()->map(function (array $entry) use (&$balance): array {
            $balance = round($balance + $entry['charge'] - $entry['payment'], 2);
            unset($entry['sort']);
            $entry['balance'] = $balance;

            return $entry;
        })->all();

        return [
            'vendor' => [
                'id' => $vendor->id,
                'vendor_code' => $vendor->vendor_code,
                'name' => $vendor->name,
            ],
            'from' => $from?->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => round($opening, 2),
            'entries' => $ordered,
            'closing_balance' => round($balance, 2),
        ];
    }

    /**
     * Outstanding payable owed to a vendor strictly before $date: posted
     * invoice totals less posted payment amounts.
     */
    private function balanceBefore(int $vendorId, Carbon $date): float
    {
        $charged = (float) VendorInvoice::query()
            ->where('vendor_id', $vendorId)
            ->where('status', 'posted')
            ->whereDate('invoice_date', '<', $date->toDateString())
            ->sum('total_amount');

        $paid = (float) VendorPayment::query()
            ->where('vendor_id', $vendorId)
            ->where('status', 'posted')
            ->whereDate('payment_date', '<', $date->toDateString())
            ->sum('amount');

        return round($charged - $paid, 2);
    }

    /**
     * Sum of allocations settling each invoice via payments posted on/before
     * $asOf, keyed by invoice id.
     *
     * @param  list<int>  $invoiceIds
     * @return array<int, float>
     */
    private function paidAsOf(array $invoiceIds, Carbon $asOf): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        return VendorPaymentAllocation::query()
            ->join('vendor_payments', 'vendor_payments.id', '=', 'vendor_payment_allocations.vendor_payment_id')
            ->where('vendor_payments.status', 'posted')
            ->whereDate('vendor_payments.payment_date', '<=', $asOf->toDateString())
            ->whereIn('vendor_payment_allocations.vendor_invoice_id', $invoiceIds)
            ->groupBy('vendor_payment_allocations.vendor_invoice_id')
            ->selectRaw('vendor_payment_allocations.vendor_invoice_id as invoice_id, SUM(vendor_payment_allocations.amount) as paid')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->invoice_id => round((float) $row->paid, 2)])
            ->all();
    }

    private function bucketFor(int $daysPast): string
    {
        return match (true) {
            $daysPast <= 0 => 'current',
            $daysPast <= 30 => '1_30',
            $daysPast <= 60 => '31_60',
            $daysPast <= 90 => '61_90',
            $daysPast <= 120 => '91_120',
            default => '120_plus',
        };
    }

    /** @return array<string, mixed> */
    private function emptyVendorRow(?Vendor $vendor): array
    {
        return [
            'vendor_id' => $vendor?->id,
            'vendor_code' => $vendor?->vendor_code,
            'vendor_name' => $vendor?->name ?? '(unknown)',
            'current' => 0.0,
            '1_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            '91_120' => 0.0,
            '120_plus' => 0.0,
            'total' => 0.0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function columnTotals(array $rows): array
    {
        $totals = array_fill_keys([...self::BUCKETS, 'total'], 0.0);

        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] = round($totals[$key] + (float) $row[$key], 2);
            }
        }

        return $totals;
    }
}
