<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6 — Expense claims. A direct-pay expense: draft → approval → post
 * (Dr expense accounts net + Dr recoverable input VAT / Cr the pay-from account,
 * a bank/cash/payable). `credit_account_id` is what the total is paid out of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('claim_number', 30)->unique()->comment('System, e.g. EXP-2026-000001');
            $table->string('claimant', 120)->comment('Who incurred the expense (employee/payee)');
            $table->date('expense_date');
            $table->foreignId('credit_account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Paid from — bank / cash / payable (credited)');
            $table->string('payment_method', 30)->default('bank_transfer');
            $table->string('reference', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'posted', 'rejected', 'cancelled'])
                ->default('draft')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->string('batch_number', 30)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('expense_claim_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_claim_id')->constrained('expense_claims')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Expense account (debited)');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 18, 2)->comment('Net amount');
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_lines');
        Schema::dropIfExists('expense_claims');
    }
};
