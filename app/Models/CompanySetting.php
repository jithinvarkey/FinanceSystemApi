<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * F16 — Seller/organization identity (single row). Used for ZATCA e-invoice QR
 * codes and document headers.
 */
final class CompanySetting extends Model
{
    protected $fillable = [
        'company_name', 'vat_number', 'commercial_reg_no',
        'address', 'city', 'postal_code', 'phone', 'email',
    ];

    /** The single settings row (created by migration; created on demand otherwise). */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['company_name' => 'Company']);
    }
}
