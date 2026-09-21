<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for one journal line.
 *
 * @mixin \App\Models\JournalLine
 */
final class JournalLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_no' => $this->line_no,
            'account_id' => $this->account_id,
            'account_code' => $this->whenLoaded('account', fn () => $this->account->code),
            'account_name' => $this->whenLoaded('account', fn () => $this->account->name),
            'cost_center_id' => $this->cost_center_id,
            'description' => $this->description,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
        ];
    }
}
