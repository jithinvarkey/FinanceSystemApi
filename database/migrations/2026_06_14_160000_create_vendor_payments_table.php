<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.11–P3.15 — Vendor payments. A payment settles one or more posted vendor
 * invoices: draft -> approval (P0.4) -> post (Dr vendor payable → Cr bank).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_number', 30)->unique()->comment('System, e.g. PMT-2026-000001');
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->date('payment_date');
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Credit side — a postable bank/cash GL account');
            $table->enum('payment_method', ['bank_transfer', 'cheque', 'cash'])->default('bank_transfer');
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

        Schema::create('vendor_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_payment_id')->constrained('vendor_payments')->cascadeOnDelete();
            $table->foreignId('vendor_invoice_id')->constrained('vendor_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['vendor_payment_id', 'vendor_invoice_id'], 'vp_alloc_unique');
        });

        Schema::table('vendor_invoices', function (Blueprint $table): void {
            $table->decimal('amount_paid', 18, 2)->default(0)->after('total_amount')
                ->comment('Settled by payments; balance_due = total_amount − amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_invoices', fn (Blueprint $table) => $table->dropColumn('amount_paid'));
        Schema::dropIfExists('vendor_payment_allocations');
        Schema::dropIfExists('vendor_payments');
    }
};
