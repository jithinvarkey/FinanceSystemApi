<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinanceRuleException;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FxRevaluation;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F22 — Foreign-currency revaluation. Marks foreign-currency monetary balances
 * (cash, AR, AP held in a non-base currency) to the closing rate at a period
 * end, and posts the net unrealized FX gain/loss. Only the base valuation moves
 * — the foreign-currency balance is unchanged.
 */
final class FxRevaluationService
{
    use ResolvesPostableAccount;

    public function __construct(private readonly GlPostingService $posting)
    {
    }

    /**
     * Per account & currency: foreign balance, current book (base) balance, the
     * closing rate, the revalued base and the adjustment to post.
     *
     * @return array{base_currency: string, as_of: string, rows: list<array<string,mixed>>, net_adjustment: float}
     */
    public function preview(Carbon $asOf): array
    {
        $base = (string) (Currency::query()->where('is_base', true)->value('code') ?? 'SAR');

        $balances = DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->where('g.currency_code', '!=', $base)
            ->whereIn('a.account_type', ['asset', 'liability'])
            ->whereDate('g.transaction_date', '<=', $asOf->toDateString())
            ->groupBy('g.account_id', 'a.code', 'a.name', 'g.currency_code')
            ->selectRaw('g.account_id, a.code, a.name, g.currency_code,
                SUM(g.debit - g.credit) as foreign_balance,
                SUM(g.base_debit - g.base_credit) as base_balance')
            ->get();

        $rows = [];
        $net = 0.0;

        foreach ($balances as $b) {
            $foreign = round((float) $b->foreign_balance, 2);
            $currentBase = round((float) $b->base_balance, 2);
            if ($foreign === 0.0) {
                continue;
            }

            $rate = $this->closingRate($b->currency_code, $asOf);
            if ($rate === null) {
                continue; // no rate on/before the date → cannot revalue
            }

            $revalued = round($foreign * $rate, 2);
            $adjustment = round($revalued - $currentBase, 2);

            $rows[] = [
                'account_id' => (int) $b->account_id,
                'account_code' => $b->code,
                'account_name' => $b->name,
                'currency' => $b->currency_code,
                'foreign_balance' => $foreign,
                'current_base' => $currentBase,
                'closing_rate' => $rate,
                'revalued_base' => $revalued,
                'adjustment' => $adjustment,
            ];
            $net = round($net + $adjustment, 2);
        }

        return ['base_currency' => $base, 'as_of' => $asOf->toDateString(), 'rows' => $rows, 'net_adjustment' => $net];
    }

    /**
     * Post the revaluation: each account moves to its revalued base, with the
     * net offset to the FX gain/loss account.
     */
    public function post(Carbon $asOf, int $fxAccountId, int $userId): FxRevaluation
    {
        $preview = $this->preview($asOf);
        $rows = array_values(array_filter($preview['rows'], fn (array $r): bool => $r['adjustment'] !== 0.0));

        if ($rows === []) {
            throw new FinanceRuleException('Nothing to revalue: all foreign balances already match the closing rate.');
        }

        $fxId = $this->resolvePostable($fxAccountId);

        return DB::transaction(function () use ($asOf, $rows, $fxId, $fxAccountId, $userId, $preview): FxRevaluation {
            $revaluation = FxRevaluation::query()->create([
                'as_of' => $asOf->toDateString(),
                'fx_account_id' => $fxAccountId,
                'net_adjustment' => $preview['net_adjustment'],
                'created_by' => $userId,
            ]);

            $lines = [];
            foreach ($rows as $r) {
                $a = (float) $r['adjustment'];
                $lines[] = $a > 0
                    ? new PostingLine($this->resolvePostable($r['account_id']), $a, 0.0, 'SAR', 1.0, null, 'FX revaluation — '.$r['currency'])
                    : new PostingLine($this->resolvePostable($r['account_id']), 0.0, abs($a), 'SAR', 1.0, null, 'FX revaluation — '.$r['currency']);
            }

            // Net offset to the FX gain/loss account (balances the batch).
            $net = (float) $preview['net_adjustment'];
            $lines[] = $net > 0
                ? new PostingLine($fxId, 0.0, $net, 'SAR', 1.0, null, 'Unrealized FX gain')
                : new PostingLine($fxId, abs($net), 0.0, 'SAR', 1.0, null, 'Unrealized FX loss');

            $glRows = $this->posting->post($revaluation, $asOf, $lines, $userId);
            $revaluation->update(['batch_number' => $glRows->first()->batch_number]);

            return $revaluation->fresh();
        });
    }

    private function closingRate(string $currencyCode, Carbon $asOf): ?float
    {
        $rate = ExchangeRate::query()
            ->join('currencies', 'currencies.id', '=', 'exchange_rates.currency_id')
            ->where('currencies.code', $currencyCode)
            ->whereDate('exchange_rates.rate_date', '<=', $asOf->toDateString())
            ->orderByDesc('exchange_rates.rate_date')
            ->value('exchange_rates.rate');

        return $rate !== null ? (float) $rate : null;
    }
}
