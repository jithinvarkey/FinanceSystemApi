<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-0003 — Currency setup: base + foreign currencies with dated exchange rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 3)->unique()->comment('ISO 4217 e.g. SAR, USD');
            $table->string('name', 80);
            $table->string('symbol', 8)->comment('Display symbol');
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_base')->default(false)->comment('Exactly one base currency allowed');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('currency_id')->constrained()->cascadeOnDelete();
            $table->date('rate_date')->comment('Effective date of the rate');
            $table->decimal('rate', 18, 8)->comment('Units of base currency per 1 unit of this currency');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['currency_id', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
