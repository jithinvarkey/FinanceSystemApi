<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\CustomerInvoice;
use App\Models\Policy;
use App\Models\Receipt;
use Illuminate\Support\Carbon;

/**
 * E2 — Customer 360: one consolidated financial view of a customer — KPIs,
 * policies, invoices, receipts, aging and the collection activity log.
 */
final class CustomerProfileService
{
    /** @return array<string, mixed> */
    public function profile(Customer $customer): array
    {
        $today = Carbon::today();

        $invoices = CustomerInvoice::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('invoice_date')->orderByDesc('id')->get();

        $posted = $invoices->where('status', 'posted');
        $outstanding = round((float) $posted->sum(fn (CustomerInvoice $i): float => (float) $i->total_amount - (float) $i->amount_paid), 2);

        $policies = Policy::query()->where('customer_id', $customer->id)->where('status', 'issued')->get();
        $receipts = Receipt::query()->where('customer_id', $customer->id)->where('status', 'posted')
            ->orderByDesc('receipt_date')->limit(10)->get();

        $creditLimit = (float) $customer->credit_limit;

        return [
            'customer' => [
                'id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'name' => $customer->name,
                'customer_type' => $customer->customer_type,
                'trn' => $customer->trn,
                'status' => $customer->status,
                'is_blocked' => (bool) $customer->is_blocked,
                'payment_terms_days' => $customer->payment_terms_days,
                'credit_limit' => round($creditLimit, 2),
            ],
            'kpis' => [
                'outstanding' => $outstanding,
                'credit_available' => round($creditLimit - $outstanding, 2),
                'over_limit' => $creditLimit > 0 && $outstanding > $creditLimit,
                'lifetime_premium' => round((float) $policies->sum('gross_premium'), 2),
                'lifetime_commission' => round((float) $policies->sum('commission_amount'), 2),
                'policies_count' => $policies->count(),
                'invoices_count' => $invoices->count(),
            ],
            'aging' => $this->aging($posted, $today),
            'policies' => $policies->sortByDesc('start_date')->take(10)->map(fn (Policy $p): array => [
                'policy_number' => $p->policy_number,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'gross_premium' => $p->gross_premium,
                'commission_amount' => $p->commission_amount,
            ])->values(),
            'invoices' => $invoices->take(10)->map(fn (CustomerInvoice $i): array => [
                'invoice_number' => $i->invoice_number,
                'invoice_date' => $i->invoice_date?->toDateString(),
                'total_amount' => $i->total_amount,
                'balance' => round((float) $i->total_amount - (float) $i->amount_paid, 2),
                'status' => $i->status->value,
            ])->values(),
            'receipts' => $receipts->map(fn (Receipt $r): array => [
                'receipt_number' => $r->receipt_number,
                'receipt_date' => $r->receipt_date?->toDateString(),
                'amount' => $r->amount,
            ])->values(),
            'activities' => $this->activities($customer),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function activities(Customer $customer): array
    {
        return CustomerActivity::query()
            ->where('customer_id', $customer->id)
            ->with('author:id,name')
            ->latest()->limit(50)->get()
            ->map(fn (CustomerActivity $a): array => [
                'id' => $a->id,
                'activity_type' => $a->activity_type,
                'note' => $a->note,
                'author' => $a->author?->name,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all();
    }

    public function addActivity(Customer $customer, string $type, string $note, int $userId): CustomerActivity
    {
        return CustomerActivity::query()->create([
            'customer_id' => $customer->id,
            'activity_type' => $type,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CustomerInvoice>  $posted
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
