<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\PolicyStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P4.17 — Policy issued by an insurer to a customer through the broker.
 *
 * @property PolicyStatus $status
 */
final class Policy extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'policy_number', 'source_system', 'external_id', 'insurer_policy_no', 'quote_number',
        'customer_id', 'insurer_id', 'product_id', 'renewed_from_policy_id',
        'start_date', 'end_date', 'currency_code', 'exchange_rate', 'payment_method', 'tax_code_id',
        'net_premium', 'premium_tax_amount', 'gross_premium',
        'commission_rate', 'commission_amount', 'commission_tax_amount', 'premium_collected', 'insurer_settled',
        'status', 'fiscal_period_id', 'batch_number', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at', 'issued_by', 'issued_at',
    ];

    protected $casts = [
        'status' => PolicyStatus::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'exchange_rate' => 'decimal:8',
        'net_premium' => 'decimal:2',
        'premium_tax_amount' => 'decimal:2',
        'gross_premium' => 'decimal:2',
        'commission_rate' => 'decimal:4',
        'commission_amount' => 'decimal:2',
        'commission_tax_amount' => 'decimal:2',
        'premium_collected' => 'decimal:2',
        'insurer_settled' => 'decimal:2',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    /** Amount the broker owes the insurer = gross premium less commission (incl. its VAT). */
    public function netDueToInsurer(): float
    {
        return round((float) $this->gross_premium - (float) $this->commission_amount - (float) $this->commission_tax_amount, 2);
    }

    /** Gross premium still to be collected from the customer (Slice B §8.3). */
    public function premiumBalanceDue(): float
    {
        return round((float) $this->gross_premium - (float) $this->premium_collected, 2);
    }

    /** Net premium still to be remitted to the insurer (Slice B §8.4). */
    public function insurerBalanceDue(): float
    {
        return round($this->netDueToInsurer() - (float) $this->insurer_settled, 2);
    }

    /** @return HasMany<PolicyInstallment> */
    public function installments(): HasMany
    {
        return $this->hasMany(PolicyInstallment::class)->orderBy('sequence');
    }

    /** @return HasMany<PolicyEndorsement> */
    public function endorsements(): HasMany
    {
        return $this->hasMany(PolicyEndorsement::class)->orderBy('id');
    }

    /** @return HasMany<PolicyCancellation> */
    public function cancellations(): HasMany
    {
        return $this->hasMany(PolicyCancellation::class)->orderBy('id');
    }

    /** @return BelongsTo<Customer, Policy> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Vendor, Policy> */
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'insurer_id');
    }

    /** @return BelongsTo<Product, Policy> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The expiring policy this one renews. @return BelongsTo<Policy, Policy> */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'renewed_from_policy_id');
    }

    /** The policy that renews this one (if any). @return HasOne<Policy> */
    public function renewal(): HasOne
    {
        return $this->hasOne(Policy::class, 'renewed_from_policy_id');
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();

        $this->forceFill([
            'status' => PolicyStatus::Approved,
            'approved_by' => $last?->actor_id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();

        $this->forceFill([
            'status' => PolicyStatus::Rejected,
            'rejection_reason' => $rejection?->comment,
        ])->save();
    }
}
