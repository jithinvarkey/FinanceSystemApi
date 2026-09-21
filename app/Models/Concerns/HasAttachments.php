<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * P0.6 — Mix into any document that can carry supporting files.
 */
trait HasAttachments
{
    /** @return MorphMany<Attachment> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }
}
