<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a recurring journal template.
 *
 * @mixin \App\Models\RecurringJournal
 */
final class RecurringJournalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_code' => $this->template_code,
            'name' => $this->name,
            'description' => $this->description,
            'frequency' => $this->frequency->value,
            'day_of_month' => $this->day_of_month,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'next_run_date' => $this->next_run_date->toDateString(),
            'status' => $this->status,
            'currency_code' => $this->currency_code,
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            'generated_count' => $this->whenCounted('generatedJournals'),
        ];
    }
}
