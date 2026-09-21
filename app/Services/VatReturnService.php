<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\VendorInvoiceStatus;
use App\Models\ChartOfAccount;
use App\Models\TaxCode;
use App\Models\VendorInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P9 — ZATCA VAT return. Output VAT (collected on sales/commission) less
 * recoverable Input VAT (paid on costs) = net VAT payable, for a tax period.
 *
 * VAT amounts are read straight from the GL on the VAT control accounts the tax
 * codes point to (output_account_id / input_account_id) and their postable
 * sub-accounts — so the return always ties to the ledger.
 */
final class VatReturnService
{
    /**
     * @return array{
     *     from: ?string, to: string,
     *     output_vat: list<array<string,mixed>>, output_vat_total: float,
     *     input_vat: list<array<string,mixed>>, input_vat_total: float,
     *     reverse_charge_base: float, reverse_charge_vat: float,
     *     net_vat_payable: float
     * }
     */
    public function vatReturn(?Carbon $from = null, ?Carbon $to = null): array
    {
        $to = $to ?? Carbon::today();

        $outputAccounts = $this->subtreeAccountIds($this->vatAccountCodes('output_account_id'));
        $inputAccounts = $this->subtreeAccountIds($this->vatAccountCodes('input_account_id', recoverableOnly: true));

        $output = $this->vatLines($outputAccounts, $from, $to, creditPositive: true);
        $input = $this->vatLines($inputAccounts, $from, $to, creditPositive: false);

        $outputTotal = round(array_sum(array_column($output, 'amount')), 2);
        $inputTotal = round(array_sum(array_column($input, 'amount')), 2);

        $rcm = $this->reverseChargeTotals($from, $to);

        return [
            'from' => $from?->toDateString(),
            'to' => $to->toDateString(),
            'output_vat' => $output,
            'output_vat_total' => $outputTotal,
            'input_vat' => $input,
            'input_vat_total' => $inputTotal,
            // ZATCA "Imports subject to the reverse-charge mechanism" memo — the
            // self-assessed VAT here is already inside both totals above (it nets
            // to zero), shown separately for the return's RCM box.
            'reverse_charge_base' => $rcm['base'],
            'reverse_charge_vat' => $rcm['vat'],
            'net_vat_payable' => round($outputTotal - $inputTotal, 2),
        ];
    }

    /**
     * Self-assessed reverse-charge totals from posted import bills in the period
     * (net base + the VAT accounted on both sides). Memo for the ZATCA RCM box.
     *
     * @return array{base: float, vat: float}
     */
    private function reverseChargeTotals(?Carbon $from, ?Carbon $to): array
    {
        $bills = VendorInvoice::query()
            ->where('reverse_charge', true)
            ->where('status', VendorInvoiceStatus::Posted->value)
            ->when($from !== null, fn ($q) => $q->whereDate('invoice_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('invoice_date', '<=', $to->toDateString()))
            ->get(['subtotal', 'tax_amount']);

        return [
            'base' => round((float) $bills->sum('subtotal'), 2),
            'vat' => round((float) $bills->sum('tax_amount'), 2),
        ];
    }

    /**
     * Distinct VAT control-account codes referenced by the tax codes.
     *
     * @return list<string>
     */
    private function vatAccountCodes(string $column, bool $recoverableOnly = false): array
    {
        $ids = TaxCode::query()
            ->when($recoverableOnly, fn ($q) => $q->where('is_recoverable', true))
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->all();

        return ChartOfAccount::query()->whereIn('id', $ids)->pluck('code')->all();
    }

    /**
     * All account ids in or under the given VAT control-account codes (the GL
     * posts VAT to the postable leaves beneath these headers).
     *
     * @param  list<string>  $codes
     * @return list<int>
     */
    private function subtreeAccountIds(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return ChartOfAccount::query()
            ->where(function ($q) use ($codes): void {
                foreach ($codes as $code) {
                    $q->orWhere('code', 'like', $code.'%');
                }
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Per-account VAT movement over the period (non-zero only).
     *
     * @param  list<int>  $accountIds
     * @return list<array{code: string, name: string, amount: float}>
     */
    private function vatLines(array $accountIds, ?Carbon $from, ?Carbon $to, bool $creditPositive): array
    {
        if ($accountIds === []) {
            return [];
        }

        $expr = $creditPositive ? 'SUM(g.base_credit - g.base_debit)' : 'SUM(g.base_debit - g.base_credit)';

        return DB::table('gl_transactions as g')
            ->join('chart_of_accounts as a', 'a.id', '=', 'g.account_id')
            ->whereIn('g.account_id', $accountIds)
            ->when($from !== null, fn ($q) => $q->whereDate('g.transaction_date', '>=', $from->toDateString()))
            ->whereDate('g.transaction_date', '<=', $to->toDateString())
            ->groupBy('a.code', 'a.name')
            ->selectRaw("a.code, a.name, {$expr} as amount")
            ->orderBy('a.code')
            ->get()
            ->map(fn ($r): array => ['code' => $r->code, 'name' => $r->name, 'amount' => round((float) $r->amount, 2)])
            ->filter(fn (array $r): bool => $r['amount'] !== 0.0)
            ->values()
            ->all();
    }
}
