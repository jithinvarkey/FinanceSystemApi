<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.6–P4.10 — Customer (sales / commission) invoices. Draft -> approval (P0.4)
 * -> post to GL (Dr AR → Cr revenue + Cr output VAT 15%). `amount_paid` is
 * settled by receipts (P4.11). Optional link to the broker premium flow later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 30)->unique()->comment('System, e.g. SINV-2026-000001');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('reference', 100)->nullable()->comment('Policy / external reference');
            $table->string('description', 255)->nullable();
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('subtotal', 18, 2)->default(0)->comment('Net of tax');
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('amount_paid', 18, 2)->default(0)->comment('Settled by receipts (P4.11)');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'posted', 'rejected', 'cancelled'])
                ->default('draft')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->string('batch_number', 30)->nullable()->comment('GL batch on posting');
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customer_invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Revenue / commission account (credited)');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 18, 2)->comment('Net amount (taxable base)');
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['customer_invoice_id', 'line_no'], 'cust_inv_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_lines');
        Schema::dropIfExists('customer_invoices');
    }
};
