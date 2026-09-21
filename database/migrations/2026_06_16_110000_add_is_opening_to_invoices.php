<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.9b — opening sub-ledger items. A cutover brings in each still-open
 * customer/vendor invoice as a POSTED open item (preserving its original number
 * and date), so AR/AP aging and statements reconcile to the GL control accounts
 * from day one. The flag distinguishes these brought-forward items from invoices
 * raised in-system. See insurance-broker-transaction-flow.md §16.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->boolean('is_opening')->default(false)->after('status')->comment('Brought-forward open item at cutover');
        });

        Schema::table('vendor_invoices', function (Blueprint $table): void {
            $table->boolean('is_opening')->default(false)->after('status')->comment('Brought-forward open item at cutover');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', fn (Blueprint $t) => $t->dropColumn('is_opening'));
        Schema::table('vendor_invoices', fn (Blueprint $t) => $t->dropColumn('is_opening'));
    }
};
