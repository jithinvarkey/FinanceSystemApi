<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E2 — a customer collection note / communication log entry.
 */
final class CustomerActivity extends Model
{
    protected $fillable = ['customer_id', 'activity_type', 'note', 'created_by'];

    /** @return BelongsTo<User, CustomerActivity> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
