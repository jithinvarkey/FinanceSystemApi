<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.17 — Broker reference data: lines of business and products.
 *
 * A product carries the default commission rate, the VAT tax code (null/EXEMPT
 * for life business), and the commission-revenue GL account. `is_life` on the
 * LOB drives the VAT-exempt treatment of both premium and commission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lines_of_business', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->foreignId('parent_id')->nullable()->constrained('lines_of_business')->nullOnDelete();
            $table->boolean('is_life')->default(false)->comment('Life business — premium & commission are VAT-exempt');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lob_id')->constrained('lines_of_business')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->decimal('default_commission_rate', 8, 4)->default(0)->comment('Percent of net premium');
            $table->foreignId('default_tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete()
                ->comment('VAT code for premium & commission; null/EXEMPT for life');
            $table->foreignId('commission_revenue_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('lines_of_business');
    }
};
