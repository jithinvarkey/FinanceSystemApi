<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-account / unapplied customer receipts ("advances"). Money received not yet
 * tied to an invoice: Dr bank / Cr customer-advances (a liability). The balance
 * is later applied to invoices (Dr advances / Cr AR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_advances', function (Blueprint $table): void {
            $table->id();
            $table->string('advance_number', 30)->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->date('receipt_date');
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('advance_account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Customer-advances liability account (credited)');
            $table->string('payment_method', 30)->default('bank_transfer');
            $table->string('reference', 100)->nullable();
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('amount', 18, 2);
            $table->decimal('applied_amount', 18, 2)->default(0);
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
        Schema::dropIfExists('customer_advances');
    }
};
