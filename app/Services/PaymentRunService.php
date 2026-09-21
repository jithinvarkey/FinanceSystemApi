<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\VendorInvoice;
use Illuminate\Support\Facades\DB;

/**
 * AP batch payment run. Pay many outstanding vendor invoices across vendors in
 * one action: invoices are grouped by vendor and one DRAFT vendor payment is
 * created per vendor (allocating each invoice's full balance). The drafts then
 * follow the normal payment approval → post flow.
 */
final class PaymentRunService
{
    public function __construct(private readonly VendorPaymentService $payments)
    {
    }

    /**
     * @param list<int> $invoiceIds
     * @return list<array{payment_number: string, vendor: string, amount: string}>
     */
    public function run(array $invoiceIds, int $bankAccountId, string $paymentDate, string $method, int $userId): array
    {
        $invoices = VendorInvoice::query()
            ->whereIn('id', $invoiceIds)
            ->where('status', 'posted')
            ->with('vendor:id,name')
            ->get()
            ->filter(fn (VendorInvoice $i): bool => $i->balanceDue() > 0)
            ->groupBy('vendor_id');

        if ($invoices->isEmpty()) {
            throw new FinanceRuleException('None of the selected invoices have an outstanding balance.');
        }

        return DB::transaction(function () use ($invoices, $bankAccountId, $paymentDate, $method, $userId): array {
            $out = [];
            foreach ($invoices as $vendorId => $group) {
                $payment = $this->payments->createDraft([
                    'vendor_id' => $vendorId,
                    'bank_account_id' => $bankAccountId,
                    'payment_date' => $paymentDate,
                    'payment_method' => $method,
                    'reference' => 'Payment run',
                    'allocations' => $group->map(fn (VendorInvoice $i): array => [
                        'vendor_invoice_id' => $i->id,
                        'amount' => $i->balanceDue(),
                    ])->all(),
                ], $userId);

                $out[] = [
                    'payment_number' => $payment->payment_number,
                    'vendor' => $group->first()->vendor?->name ?? '',
                    'amount' => number_format((float) $payment->amount, 2, '.', ''),
                ];
            }

            return $out;
        });
    }
}
