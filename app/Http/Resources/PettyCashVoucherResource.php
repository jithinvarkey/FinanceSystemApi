<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PettyCashVoucher
 */
final class PettyCashVoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'voucher_number' => $this->voucher_number,
            'voucher_date' => $this->voucher_date?->toDateString(),
            'petty_cash_account_id' => $this->petty_cash_account_id,
            'petty_cash_account_name' => $this->whenLoaded('pettyCashAccount', fn () => $this->pettyCashAccount->name),
            'expense_account_id' => $this->expense_account_id,
            'expense_account_name' => $this->whenLoaded('expenseAccount', fn () => $this->expenseAccount->name),
            'payee' => $this->payee,
            'description' => $this->description,
            'reference' => $this->reference,
            'currency_code' => $this->currency_code,
            'amount' => $this->amount,
            'tax_code_id' => $this->tax_code_id,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'status' => $this->status->value,
            'batch_number' => $this->batch_number,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
