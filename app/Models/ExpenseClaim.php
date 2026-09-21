<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\ExpenseClaimStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P6 — An expense claim. Draft → approval → post (Dr expense + input VAT /
 * Cr the pay-from account).
 *
 * @property ExpenseClaimStatus $status
 */
final class ExpenseClaim extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'claim_number', 'claimant', 'expense_date', 'credit_account_id', 'payment_method',
        'reference', 'description', 'currency_code', 'exchange_rate',
        'subtotal', 'tax_amount', 'total_amount', 'status', 'fiscal_period_id',
        'batch_number', 'rejection_reason', 'created_by', 'approved_by', 'approved_at',
        'posted_by', 'posted_at',
    ];

    protected $casts = [
        'status' => ExpenseClaimStatus::class,
        'expense_date' => 'date',
        'exchange_rate' => 'decimal:8',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /** @return HasMany<ExpenseClaimLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<ChartOfAccount, ExpenseClaim> */
    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();

        $this->forceFill([
            'status' => ExpenseClaimStatus::Approved,
            'approved_by' => $last?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();

        $this->forceFill([
            'status' => ExpenseClaimStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
