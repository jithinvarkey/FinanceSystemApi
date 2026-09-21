<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.11–P4.14 — Customer receipts. A receipt settles one or more posted
 * customer invoices: draft -> approval (P0.4) -> post (Dr bank → Cr AR), then
 * credits each invoice's `customer_invoices.amount_paid`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number', 30)->unique()->comment('System, e.g. RCP-2026-000001');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('receipt_date');
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Debit side — a postable bank/cash GL account');
            $table->enum('payment_method', ['bank_transfer', 'cheque', 'cash', 'online'])->default('bank_transfer');
            $table->string('reference', 100)->nullable()->comment('Cheque no / transfer ref');
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('amount', 18, 2)->default(0);
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

        Schema::create('receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('receipts')->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['receipt_id', 'customer_invoice_id'], 'rcpt_alloc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_allocations');
        Schema::dropIfExists('receipts');
    }
};
