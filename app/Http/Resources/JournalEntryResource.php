<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a journal entry header with lines.
 *
 * @mixin \App\Models\JournalEntry
 */
final class JournalEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_number' => $this->journal_number,
            'journal_date' => $this->journal_date->toDateString(),
            'reference' => $this->reference,
            'description' => $this->description,
            'status' => $this->status->value,
            'currency_code' => $this->currency_code,
            'total_debit' => $this->total_debit,
            'total_credit' => $this->total_credit,
            'fiscal_period_id' => $this->fiscal_period_id,
            'recurring_journal_id' => $this->recurring_journal_id,
            'reversal_of_id' => $this->reversal_of_id,
            'is_opening' => (bool) $this->is_opening,
            'created_by' => $this->created_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
