<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalActionType;
use App\Enums\NoteType;
use App\Enums\VendorInvoiceStatus;
use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AP credit / debit note.
 *
 * @property NoteType $note_type
 * @property VendorInvoiceStatus $status
 */
final class VendorNote extends Model implements Approvable
{
    use HasApprovals;
    use HasAttachments;
    use SoftDeletes;

    protected $fillable = [
        'note_number', 'note_type', 'vendor_id', 'original_invoice_id', 'note_date',
        'reason', 'description', 'currency_code', 'exchange_rate',
        'subtotal', 'tax_amount', 'total_amount', 'status', 'fiscal_period_id',
        'batch_number', 'rejection_reason', 'created_by', 'approved_by', 'approved_at', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'note_type' => NoteType::class,
        'status' => VendorInvoiceStatus::class,
        'note_date' => 'date',
        'exchange_rate' => 'decimal:8',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    /** @return HasMany<VendorNoteLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(VendorNoteLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Vendor, VendorNote> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function onApprovalApproved(ApprovalRequest $request): void
    {
        $last = $request->actions()->where('action', ApprovalActionType::Approved->value)->latest('id')->first();
        $this->forceFill(['status' => VendorInvoiceStatus::Approved, 'approved_by' => $last?->actor_id, 'approved_at' => now(), 'rejection_reason' => null])->save();
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        $rejection = $request->actions()->where('action', ApprovalActionType::Rejected->value)->latest('id')->first();
        $this->forceFill(['status' => VendorInvoiceStatus::Rejected, 'rejection_reason' => $rejection?->comment])->save();
    }
}
