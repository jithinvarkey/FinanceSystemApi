<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F12 — early-payment (settlement) discount terms on the vendor: a % discount
 * if a bill is settled within N days of its invoice date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->decimal('settlement_discount_percent', 6, 3)->default(0)->after('payment_terms_days');
            $table->unsignedSmallInteger('settlement_discount_days')->default(0)->after('settlement_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn(['settlement_discount_percent', 'settlement_discount_days']));
    }
};
