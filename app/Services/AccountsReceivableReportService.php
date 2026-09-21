<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Receipt;
use App\Models\ReceiptAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * P4.18 — Accounts Receivable reporting: customer ledger (statement) and AR
 * aging.
 *
 * Read-only. Only POSTED documents touch the ledger, so both reports look at
 * posted customer invoices (increase what is owed to us) and posted receipts
 * (reduce it). Money is held in SAR to 2 dp, consistent with the GL.
 */
final class AccountsReceivableReportService
{
    /** Aging bucket boundaries, in days past due. */
    private const BUCKETS = ['current', '1_30', '31_60', '61_90', '91_120', '120_plus'];

    /**
     * P4.18 — AR aging as of a date.
     *
     * For every posted invoice still owing money on $asOf, the outstanding
     * amount is placed in a bucket by how far past its reference date it is
     * ($basis = 'due_date' default, or 'invoice_date'). Returns one row per
     * customer with a balance, plus grand totals.
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

        $invoices = CustomerInvoice::query()
            ->where('status', 'posted')
            ->whereDate('invoice_date', '<=', $asOf->toDateString())
            ->with('customer:id,name,customer_code')
            ->get();

        $paidAsOf = $this->paidAsOf($invoices->pluck('id')->all(), $asOf);

        /** @var Collection<int, array<string, mixed>> $byCustomer */
        $byCustomer = collect();

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

            $customerId = (int) $invoice->customer_id;
            $row = $byCustomer->get($customerId) ?? $this->emptyCustomerRow($invoice->customer);

            $row[$bucket] = round($row[$bucket] + $outstanding, 2);
            $row['total'] = round($row['total'] + $outstanding, 2);
            $byCustomer->put($customerId, $row);
        }

        $rows = $byCustomer->values()
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
     * P4.18 — Customer statement: chronological posted transactions with a
     * running balance, plus the opening balance carried in from before $from.
     *
     * @return array{
     *     customer: array<string, mixed>,
     *     from: ?string,
     *     to: string,
     *     opening_balance: float,
     *     entries: list<array<string, mixed>>,
     *     closing_balance: float
     * }
     */
    public function customerLedger(Customer $customer, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = ($to ?? Carbon::today())->endOfDay();
        $from = $from?->startOfDay();

        $opening = $from !== null
            ? $this->balanceBefore($customer->id, $from)
            : 0.0;

        $invoices = CustomerInvoice::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'posted')
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->when($from !== null, fn ($q) => $q->whereDate('invoice_date', '>=', $from->toDateString()))
            ->get(['id', 'invoice_number', 'invoice_date', 'reference', 'description', 'total_amount']);

        $receipts = Receipt::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'posted')
            ->whereDate('receipt_date', '<=', $to->toDateString())
            ->when($from !== null, fn ($q) => $q->whereDate('receipt_date', '>=', $from->toDateString()))
            ->get(['id', 'receipt_number', 'receipt_date', 'reference', 'amount']);

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

        foreach ($receipts as $receipt) {
            $entries->push([
                'sort' => $receipt->receipt_date->toDateString().'-2-'.str_pad((string) $receipt->id, 9, '0', STR_PAD_LEFT),
                'date' => $receipt->receipt_date->toDateString(),
                'type' => 'receipt',
                'reference' => $receipt->receipt_number,
                'description' => $receipt->reference,
                'charge' => 0.0,
                'payment' => round((float) $receipt->amount, 2),
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
            'customer' => [
                'id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'name' => $customer->name,
            ],
            'from' => $from?->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => round($opening, 2),
            'entries' => $ordered,
            'closing_balance' => round($balance, 2),
        ];
    }

    /**
     * Outstanding receivable owed by a customer strictly before $date: posted
     * invoice totals less posted receipt amounts.
     */
    private function balanceBefore(int $customerId, Carbon $date): float
    {
        $charged = (float) CustomerInvoice::query()
            ->where('customer_id', $customerId)
            ->where('status', 'posted')
            ->whereDate('invoice_date', '<', $date->toDateString())
            ->sum('total_amount');

        $received = (float) Receipt::query()
            ->where('customer_id', $customerId)
            ->where('status', 'posted')
            ->whereDate('receipt_date', '<', $date->toDateString())
            ->sum('amount');

        return round($charged - $received, 2);
    }

    /**
     * Sum of allocations settling each invoice via receipts posted on/before
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

        return ReceiptAllocation::query()
            ->join('receipts', 'receipts.id', '=', 'receipt_allocations.receipt_id')
            ->where('receipts.status', 'posted')
            ->whereDate('receipts.receipt_date', '<=', $asOf->toDateString())
            ->whereIn('receipt_allocations.customer_invoice_id', $invoiceIds)
            ->groupBy('receipt_allocations.customer_invoice_id')
            ->selectRaw('receipt_allocations.customer_invoice_id as invoice_id, SUM(receipt_allocations.amount) as paid')
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
    private function emptyCustomerRow(?Customer $customer): array
    {
        return [
            'customer_id' => $customer?->id,
            'customer_code' => $customer?->customer_code,
            'customer_name' => $customer?->name ?? '(unknown)',
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

    /**
     * Dunning list: every overdue posted invoice with its dunning level (1: ≤30
     * days, 2: ≤60, 3: >60). Diamond does not levy late-payment finance charges,
     * so this is a pure collections worklist — who owes, how late, which reminder.
     *
     * @return array{as_of: string, rows: list<array<string,mixed>>, totals: array{overdue: float}}
     */
    public function dunning(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->startOfDay();

        $invoices = CustomerInvoice::query()
            ->where('status', 'posted')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->with('customer:id,name,customer_code')
            ->get();

        $rows = [];
        $totalOverdue = 0.0;

        foreach ($invoices as $invoice) {
            $balance = $invoice->balanceDue();
            if ($balance <= 0) {
                continue;
            }
            $daysOverdue = (int) Carbon::parse((string) $invoice->due_date)->diffInDays($asOf);
            $level = $daysOverdue <= 30 ? 1 : ($daysOverdue <= 60 ? 2 : 3);

            $rows[] = [
                'customer_code' => $invoice->customer?->customer_code,
                'customer_name' => $invoice->customer?->name,
                'invoice_number' => $invoice->invoice_number,
                'invoice_id' => $invoice->id,
                'due_date' => Carbon::parse((string) $invoice->due_date)->toDateString(),
                'days_overdue' => $daysOverdue,
                'level' => $level,
                'balance' => round($balance, 2),
            ];
            $totalOverdue = round($totalOverdue + $balance, 2);
        }

        usort($rows, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

        return [
            'as_of' => $asOf->toDateString(),
            'rows' => $rows,
            'totals' => ['overdue' => $totalOverdue],
        ];
    }
}
