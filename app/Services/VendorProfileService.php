<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Policy;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use Illuminate\Support\Carbon;

/**
 * E11 — Vendor self-service portal (internal): one consolidated view of a vendor
 * (or insurer) — KPIs, open bills, payments, payables aging and, for insurers,
 * the policies placed with them.
 */
final class VendorProfileService
{
    /** @return array<string, mixed> */
    public function profile(Vendor $vendor): array
    {
        $today = Carbon::today();

        $invoices = VendorInvoice::query()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('invoice_date')->orderByDesc('id')->get();

        $posted = $invoices->filter(fn (VendorInvoice $i): bool => $i->status->value === 'posted');
        $outstanding = round((float) $posted->sum(fn (VendorInvoice $i): float => (float) $i->total_amount - (float) $i->amount_paid), 2);

        $payments = VendorPayment::query()->where('vendor_id', $vendor->id)
            ->where('status', 'posted')->orderByDesc('payment_date')->limit(10)->get();

        // Insurer-as-vendor: policies placed with this insurer.
        $policies = $vendor->vendor_type === 'insurer'
            ? Policy::query()->where('insurer_id', $vendor->id)->where('status', 'issued')->get()
            : collect();

        return [
            'vendor' => [
                'id' => $vendor->id,
                'vendor_code' => $vendor->vendor_code,
                'name' => $vendor->name,
                'vendor_type' => $vendor->vendor_type,
                'trn' => $vendor->trn,
                'status' => $vendor->status,
                'is_blocked' => (bool) $vendor->is_blocked,
                'payment_terms_days' => $vendor->payment_terms_days,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
            ],
            'kpis' => [
                'outstanding' => $outstanding,
                'invoices_count' => $invoices->count(),
                'open_invoices_count' => $posted->filter(fn (VendorInvoice $i): bool => (float) $i->total_amount - (float) $i->amount_paid > 0)->count(),
                'paid_ytd' => round((float) $payments->filter(fn (VendorPayment $p): bool => Carbon::parse((string) $p->payment_date)->year === $today->year)->sum('amount'), 2),
                'policies_count' => $policies->count(),
                'lifetime_premium' => round((float) $policies->sum('gross_premium'), 2),
            ],
            'aging' => $this->aging($posted, $today),
            'invoices' => $invoices->take(10)->map(fn (VendorInvoice $i): array => [
                'invoice_number' => $i->invoice_number,
                'vendor_invoice_no' => $i->vendor_invoice_no,
                'invoice_date' => $i->invoice_date?->toDateString(),
                'due_date' => $i->due_date?->toDateString(),
                'total_amount' => $i->total_amount,
                'balance' => round((float) $i->total_amount - (float) $i->amount_paid, 2),
                'status' => $i->status->value,
            ])->values(),
            'payments' => $payments->map(fn (VendorPayment $p): array => [
                'payment_number' => $p->payment_number,
                'payment_date' => $p->payment_date?->toDateString(),
                'amount' => $p->amount,
                'method' => $p->payment_method,
            ])->values(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, VendorInvoice>  $posted
     * @return array<string, float>
     */
    private function aging($posted, Carbon $today): array
    {
        $b = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '91_120' => 0.0, '120_plus' => 0.0];

        foreach ($posted as $i) {
            $bal = round((float) $i->total_amount - (float) $i->amount_paid, 2);
            if ($bal <= 0) {
                continue;
            }
            $ref = $i->due_date ?? $i->invoice_date;
            $days = (int) Carbon::parse((string) $ref)->startOfDay()->diffInDays($today, false);
            $key = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                $days <= 120 => '91_120',
                default => '120_plus',
            };
            $b[$key] = round($b[$key] + $bal, 2);
        }

        return $b;
    }
}
