<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6 — Petty cash vouchers. A small single-line cash disbursement: draft → post
 * (Dr expense + input VAT / Cr the petty-cash float account). Maker/checker is
 * enforced by different permissions on create vs post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('voucher_number', 30)->unique()->comment('System, e.g. PC-2026-000001');
            $table->date('voucher_date');
            $table->foreignId('petty_cash_account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Petty-cash float account (credited)');
            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Expense account (debited)');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('payee', 120);
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('amount', 18, 2)->comment('Net amount');
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->string('batch_number', 30)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_vouchers');
    }
};
