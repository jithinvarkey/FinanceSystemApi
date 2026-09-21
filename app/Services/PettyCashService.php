<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PettyCashStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\PettyCashVoucher;
use App\Models\TaxCode;
use App\Services\Concerns\ResolvesPostableAccount;
use App\Services\Posting\GlPostingService;
use App\Services\Posting\PostingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P6 — Petty cash vouchers. Draft → post (Dr expense + input VAT / Cr the
 * petty-cash float account). Low-value, so no multi-step approval — maker/checker
 * is the split between create (manage) and post (post) permissions.
 */
final class PettyCashService
{
    use ResolvesPostableAccount;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly GlPostingService $posting,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, int $userId): PettyCashVoucher
    {
        $computed = $this->compute($data);

        return DB::transaction(function () use ($data, $computed, $userId): PettyCashVoucher {
            $date = Carbon::parse($data['voucher_date']);

            return PettyCashVoucher::query()->create([
                'voucher_number' => $this->numbers->next('petty_cash', $date),
                'voucher_date' => $date->toDateString(),
                'petty_cash_account_id' => $data['petty_cash_account_id'],
                'expense_account_id' => $data['expense_account_id'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'payee' => $data['payee'],
                'description' => $data['description'] ?? null,
                'reference' => $data['reference'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'SAR',
                ...$computed,
                'status' => PettyCashStatus::Draft,
                'created_by' => $userId,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(PettyCashVoucher $voucher, array $data): PettyCashVoucher
    {
        if (! $voucher->status->isEditable()) {
            throw FinanceRuleException::notEditable($voucher->status->value);
        }

        $computed = $this->compute($data);
        $voucher->update([
            'voucher_date' => $data['voucher_date'],
            'petty_cash_account_id' => $data['petty_cash_account_id'],
            'expense_account_id' => $data['expense_account_id'],
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'payee' => $data['payee'],
            'description' => $data['description'] ?? null,
            'reference' => $data['reference'] ?? null,
            'currency_code' => $data['currency_code'] ?? $voucher->currency_code,
            ...$computed,
        ]);

        return $voucher->fresh();
    }

    public function deleteDraft(PettyCashVoucher $voucher): void
    {
        if ($voucher->status !== PettyCashStatus::Draft) {
            throw FinanceRuleException::notEditable($voucher->status->value);
        }

        $voucher->delete();
    }

    public function post(PettyCashVoucher $voucher, int $userId): PettyCashVoucher
    {
        if ($voucher->status !== PettyCashStatus::Draft) {
            throw FinanceRuleException::invalidTransition($voucher->status->value, PettyCashStatus::Posted->value);
        }

        $expenseId = $this->resolvePostable((int) $voucher->expense_account_id);
        $cashId = $this->resolvePostable((int) $voucher->petty_cash_account_id);

        return DB::transaction(function () use ($voucher, $userId, $expenseId, $cashId): PettyCashVoucher {
            $lines = [
                new PostingLine($expenseId, (float) $voucher->amount, 0.0, $voucher->currency_code, 1.0, $voucher->cost_center_id, $voucher->description ?? $voucher->payee),
            ];

            if ((float) $voucher->tax_amount > 0 && $voucher->tax_code_id) {
                $inputAccount = TaxCode::query()->whereKey($voucher->tax_code_id)->value('input_account_id');
                if ($inputAccount) {
                    $lines[] = new PostingLine($this->resolvePostable((int) $inputAccount), (float) $voucher->tax_amount, 0.0, $voucher->currency_code, 1.0, null, 'Input VAT');
                }
            }

            $lines[] = new PostingLine($cashId, 0.0, (float) $voucher->total_amount, $voucher->currency_code, 1.0, null, 'Petty cash — '.$voucher->payee);

            $rows = $this->posting->post($voucher, Carbon::parse($voucher->voucher_date), $lines, $userId);

            $voucher->update([
                'status' => PettyCashStatus::Posted,
                'fiscal_period_id' => $rows->first()->fiscal_period_id,
                'batch_number' => $rows->first()->batch_number,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $voucher->fresh();
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array{amount: float, tax_code_id: ?int, tax_rate: float, tax_amount: float, total_amount: float}
     */
    private function compute(array $data): array
    {
        $amount = round((float) $data['amount'], 2);
        $taxCodeId = isset($data['tax_code_id']) ? (int) $data['tax_code_id'] : null;
        $rate = $taxCodeId ? (float) (TaxCode::query()->whereKey($taxCodeId)->value('rate') ?? 0) : 0.0;
        $tax = round($amount * $rate / 100, 2);

        return [
            'amount' => $amount,
            'tax_code_id' => $taxCodeId,
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            'total_amount' => round($amount + $tax, 2),
        ];
    }
}
