<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FIN-0001 — GL account in the hierarchical chart of accounts.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $account_type
 * @property NormalBalance $normal_balance
 * @property int|null $parent_id
 * @property int $level
 * @property bool $is_postable
 * @property bool $is_bank_account
 * @property bool $is_control_account
 * @property RecordStatus $status
 */
final class ChartOfAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'account_type', 'normal_balance', 'parent_id', 'level',
        'is_postable', 'is_bank_account', 'is_control_account', 'status',
        'description', 'created_by',
    ];

    protected $casts = [
        'account_type' => AccountType::class,
        'normal_balance' => NormalBalance::class,
        'status' => RecordStatus::class,
        'is_postable' => 'boolean',
        'is_bank_account' => 'boolean',
        'is_control_account' => 'boolean',
        'level' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function glTransactions(): HasMany
    {
        return $this->hasMany(GlTransaction::class, 'account_id');
    }

    /**
     * Scope: accounts that may receive journal postings.
     */
    public function scopePostable($query)
    {
        return $query->where('is_postable', true)->where('status', RecordStatus::Active);
    }
}
