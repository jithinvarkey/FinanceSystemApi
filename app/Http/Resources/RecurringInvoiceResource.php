<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RecurringInvoice
 */
final class RecurringInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'party_id' => $this->party_id,
            'name' => $this->name,
            'frequency' => $this->frequency->value,
            'day_of_month' => $this->day_of_month,
            'start_date' => $this->start_date?->toDateString(),
            'next_run_date' => $this->next_run_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_active' => $this->is_active,
            'due_days' => $this->due_days,
            'reference' => $this->reference,
            'description' => $this->description,
            'lines' => $this->lines,
            'generated_count' => $this->generated_count,
            'last_generated_at' => $this->last_generated_at?->toIso8601String(),
        ];
    }
}
