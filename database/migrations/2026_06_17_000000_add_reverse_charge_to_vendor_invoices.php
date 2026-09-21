<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F14 — Reverse-charge VAT (RCM) on imports.
 *
 * A reverse-charge bill is an import of services/goods where the (usually
 * foreign) vendor charges no VAT; the buyer self-assesses output VAT and
 * simultaneously reclaims it as input VAT (net-zero cash). The flag drives the
 * balanced posting (Dr input VAT / Cr output VAT) and the ZATCA return memo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table): void {
            $table->boolean('reverse_charge')->default(false)->after('withholding_amount');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table): void {
            $table->dropColumn('reverse_charge');
        });
    }
};
