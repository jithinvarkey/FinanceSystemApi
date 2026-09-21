<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AR credit & debit notes. A credit note reverses revenue + output VAT and
 * reduces the customer's receivable (and can be applied to the original
 * invoice). A debit note is the opposite (an additional charge). Draft →
 * approval → post, like an invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('note_number', 30)->unique();
            $table->enum('note_type', ['credit', 'debit'])->index();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('original_invoice_id')->nullable()->constrained('customer_invoices')->nullOnDelete();
            $table->date('note_date');
            $table->string('reason', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'posted', 'rejected', 'cancelled'])->default('draft')->index();
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

        Schema::create('customer_note_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_note_id')->constrained('customer_notes')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 18, 2);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_note_lines');
        Schema::dropIfExists('customer_notes');
    }
};
