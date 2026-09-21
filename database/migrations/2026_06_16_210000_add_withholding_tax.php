<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F13 — withholding tax (KSA WHT on payments to non-residents). A default WHT
 * rate per vendor; the WHT withheld per bill is tracked so the WHT payable can
 * be reported and remitted to ZATCA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->decimal('wht_rate', 6, 3)->default(0)->after('settlement_discount_days')->comment('Withholding tax % (e.g. 5/15/20)');
        });
        Schema::table('vendor_invoices', function (Blueprint $table): void {
            $table->decimal('withholding_amount', 18, 2)->default(0)->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn('wht_rate'));
        Schema::table('vendor_invoices', fn (Blueprint $t) => $t->dropColumn('withholding_amount'));
    }
};
