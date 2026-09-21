<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TreasuryItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E7 — Treasury & cash forecasting. Reports the current cash position per bank
 * account and projects a weekly cash-flow forecast: opening cash, expected AR
 * receipts, AP payments, and manual treasury items, with a running balance.
 */
final class TreasuryService
{
    /**
     * Current balance of every bank/cash account plus the total.
     *
     * @return array{accounts: list<array{account_id: int, code: string, name: string, balance: float}>, total: float}
     */
    public function cashPositions(): array
    {
        $rows = DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->where('a.is_bank_account', true)
            ->groupBy('a.id', 'a.code', 'a.name')
            ->selectRaw('a.id account_id, a.code, a.name, SUM(g.base_debit - g.base_credit) balance')
            ->orderBy('a.code')
            ->get();

        $accounts = $rows->map(fn ($r): array => [
            'account_id' => (int) $r->account_id,
            'code' => (string) $r->code,
            'name' => (string) $r->name,
            'balance' => round((float) $r->balance, 2),
        ])->all();

        return ['accounts' => $accounts, 'total' => round(array_sum(array_column($accounts, 'balance')), 2)];
    }

    /**
     * Weekly cash-flow forecast for the next $weeks weeks.
     *
     * @return array{opening: float, weeks: list<array{week: int, start: string, end: string, ar_in: float, ap_out: float, treasury_in: float, treasury_out: float, net: float, closing: float}>}
     */
    public function forecast(int $weeks = 12, ?Carbon $from = null): array
    {
        $weeks = max(1, min(52, $weeks));
        $start = ($from ?? Carbon::now())->copy()->startOfWeek();
        $opening = $this->cashPositions()['total'];

        $running = $opening;
        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $wStart = $start->copy()->addWeeks($i);
            $wEnd = $wStart->copy()->endOfWeek();
            // The last bucket sweeps up everything still outstanding beyond the horizon.
            $upper = $i === $weeks - 1 ? null : $wEnd;
            $lowerInclusive = $i === 0 ? null : $wStart;   // first bucket also captures already-overdue items

            $arIn = $this->dueBalance('customer_invoices', $lowerInclusive, $upper);
            $apOut = $this->dueBalance('vendor_invoices', $lowerInclusive, $upper);
            $treIn = $this->treasuryTotal('in', $lowerInclusive, $upper);
            $treOut = $this->treasuryTotal('out', $lowerInclusive, $upper);

            $net = round($arIn + $treIn - $apOut - $treOut, 2);
            $running = round($running + $net, 2);

            $buckets[] = [
                'week' => $i + 1,
                'start' => $wStart->toDateString(),
                'end' => $wEnd->toDateString(),
                'ar_in' => $arIn, 'ap_out' => $apOut,
                'treasury_in' => $treIn, 'treasury_out' => $treOut,
                'net' => $net, 'closing' => $running,
            ];
        }

        return ['opening' => $opening, 'weeks' => $buckets];
    }

    /** Outstanding balance of posted invoices due in [from, to]. Null `from` sweeps everything ≤ to (incl. overdue). */
    private function dueBalance(string $table, ?Carbon $from, ?Carbon $to): float
    {
        return round((float) (DB::table($table)
            ->where('status', 'posted')
            ->when($from !== null, fn ($q) => $q->whereDate('due_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('due_date', '<=', $to->toDateString()))
            ->selectRaw('SUM(total_amount - amount_paid) bal')
            ->value('bal') ?? 0), 2);
    }

    private function treasuryTotal(string $direction, ?Carbon $from, ?Carbon $to): float
    {
        return round((float) (TreasuryItem::query()
            ->where('status', 'planned')
            ->where('direction', $direction)
            ->when($from !== null, fn ($q) => $q->whereDate('expected_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('expected_date', '<=', $to->toDateString()))
            ->sum('amount')), 2);
    }
}
