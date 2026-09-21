<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P0.6 — Validates an attachment upload (type, size, owner, retention).
 */
final class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attachable_type' => ['required', 'string', 'max:100'],
            'attachable_id' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,doc,docx,xls,xlsx,csv'],
            'category' => ['nullable', 'string', 'max:60'],
            'retention_years' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
