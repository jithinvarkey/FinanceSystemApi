<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E3 — Risk & anomaly indicators (rule-based, no AI). Scans posted data for
 * patterns a controller should review: duplicate payments, commission outliers,
 * manual-journal spikes and large refunds.
 */
final class RiskScanService
{
    private const DUP_DAYS = 7;
    private const JOURNAL_SPIKE = 10;       // manual journals in one day
    private const LARGE_REFUND = 10000.0;   // credit-note total flagged for review

    /** @return array{summary: array<string,int>, findings: list<array<string,mixed>>} */
    public function scan(): array
    {
        $findings = [
            ...$this->duplicatePayments(),
            ...$this->commissionOutliers(),
            ...$this->manualJournalSpikes(),
            ...$this->largeRefunds(),
        ];

        $summary = [];
        foreach ($findings as $f) {
            $summary[$f['category']] = ($summary[$f['category']] ?? 0) + 1;
        }

        // Highest-severity first.
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($findings, fn ($a, $b) => ($rank[$a['severity']] <=> $rank[$b['severity']]));

        return ['summary' => $summary, 'findings' => $findings];
    }

    /** Posted payments to the same vendor for the same amount within a few days. */
    private function duplicatePayments(): array
    {
        // Same vendor + amount pairs (DB does the cheap join); date proximity is
        // checked in PHP so it works on both SQLite (tests) and MySQL (prod).
        $rows = DB::table('vendor_payments as p1')
            ->join('vendor_payments as p2', function ($j): void {
                $j->on('p1.vendor_id', '=', 'p2.vendor_id')
                    ->on('p1.amount', '=', 'p2.amount')
                    ->on('p1.id', '<', 'p2.id');
            })
            ->join('vendors as v', 'v.id', '=', 'p1.vendor_id')
            ->where('p1.amount', '>', 0)
            ->whereIn('p1.status', ['approved', 'posted'])
            ->whereIn('p2.status', ['approved', 'posted'])
            ->selectRaw('v.name vendor, p1.amount, p1.payment_number a, p2.payment_number b, p1.payment_date d1, p2.payment_date d2')
            ->get()
            ->filter(fn ($r): bool => abs(Carbon::parse($r->d1)->diffInDays(Carbon::parse($r->d2))) <= self::DUP_DAYS);

        return $rows->map(fn ($r): array => [
            'category' => 'duplicate_payment',
            'severity' => 'high',
            'title' => 'Possible duplicate payment to '.$r->vendor,
            'detail' => "{$r->a} ({$r->d1}) and {$r->b} ({$r->d2}) both for ".number_format((float) $r->amount, 2).' SAR.',
        ])->values()->all();
    }

    /** Policies whose commission rate is well above the product default. */
    private function commissionOutliers(): array
    {
        $rows = DB::table('policies as p')
            ->join('products as pr', 'pr.id', '=', 'p.product_id')
            ->where('pr.default_commission_rate', '>', 0)
            ->whereColumn('p.commission_rate', '>', DB::raw('pr.default_commission_rate * 1.5'))
            ->where('p.status', 'issued')
            ->selectRaw('p.policy_number, p.commission_rate, pr.name product, pr.default_commission_rate def')
            ->limit(50)->get();

        return $rows->map(fn ($r): array => [
            'category' => 'commission_outlier',
            'severity' => 'medium',
            'title' => 'Unusual commission on '.$r->policy_number,
            'detail' => "Rate {$r->commission_rate}% vs {$r->product} default {$r->def}%.",
        ])->all();
    }

    /** Days with an unusually high number of manual journals. */
    private function manualJournalSpikes(): array
    {
        $rows = DB::table('journal_entries')
            ->where('status', 'posted')
            ->whereNull('recurring_journal_id')
            ->whereNull('reversal_of_id')
            ->where('is_opening', false)
            ->groupBy('journal_date')
            ->havingRaw('COUNT(*) >= ?', [self::JOURNAL_SPIKE])
            ->selectRaw('journal_date, COUNT(*) c')
            ->get();

        return $rows->map(fn ($r): array => [
            'category' => 'journal_spike',
            'severity' => 'medium',
            'title' => "Manual-journal spike on {$r->journal_date}",
            'detail' => "{$r->c} manual journals posted in one day.",
        ])->all();
    }

    /** Credit notes (refunds) above the review threshold. */
    private function largeRefunds(): array
    {
        $rows = DB::table('customer_notes as n')
            ->join('customers as c', 'c.id', '=', 'n.customer_id')
            ->where('n.note_type', 'credit')
            ->where('n.total_amount', '>=', self::LARGE_REFUND)
            ->selectRaw('n.note_number, n.total_amount, n.note_date, c.name customer')
            ->orderByDesc('n.total_amount')->limit(50)->get();

        return $rows->map(fn ($r): array => [
            'category' => 'large_refund',
            'severity' => 'low',
            'title' => 'Large credit note '.$r->note_number,
            'detail' => number_format((float) $r->total_amount, 2).' SAR credited to '.$r->customer.' on '.$r->note_date.'.',
        ])->all();
    }
}
