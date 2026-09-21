<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.17 — Policies. A policy is issued by an insurer to a customer through the
 * broker. On issue it posts the fiduciary dual entry (see the broker design doc
 * §8): Dr customer receivable / Cr insurer payable (gross premium), then
 * Dr insurer payable / Cr commission revenue + Cr output VAT (the commission
 * the broker keeps). Premium is never broker revenue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table): void {
            $table->id();
            $table->string('policy_number', 30)->unique()->comment('System, e.g. POL-2026-000001');
            $table->string('insurer_policy_no', 60)->nullable()->comment("Insurer's own policy number");
            $table->string('quote_number', 60)->nullable();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('insurer_id')->constrained('vendors')->restrictOnDelete()->comment('A vendor of type insurer');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->enum('payment_method', ['full', 'installment'])->default('full');
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();

            // Premium (the insurer's money) + commission (the broker's revenue).
            $table->decimal('net_premium', 18, 2)->default(0);
            $table->decimal('premium_tax_amount', 18, 2)->default(0)->comment("Insurer's output VAT, pass-through");
            $table->decimal('gross_premium', 18, 2)->default(0);
            $table->decimal('commission_rate', 8, 4)->default(0);
            $table->decimal('commission_amount', 18, 2)->default(0);
            $table->decimal('commission_tax_amount', 18, 2)->default(0)->comment("Broker's output VAT on commission");

            $table->enum('status', ['draft', 'pending_approval', 'approved', 'issued', 'cancelled', 'expired', 'rejected'])
                ->default('draft')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->string('batch_number', 30)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
