<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.1–P4.5 — Customer master with onboarding approval, compliance (Saudi VAT
 * number / CR + expiry), credit limit (P4.2), bank details for refunds (P4.4),
 * and blocking (P4.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_code', 30)->unique()->comment('Generated, e.g. CUST-2026-000001');
            $table->string('name', 180)->comment('Legal name');
            $table->string('trade_name', 180)->nullable();
            $table->enum('customer_type', ['individual', 'corporate', 'government', 'broker', 'other'])
                ->default('corporate');

            // Compliance
            $table->string('trn', 20)->nullable()->comment('Saudi VAT registration number, 15 digits');
            $table->string('commercial_reg_no', 60)->nullable()->comment('CR number');
            $table->date('commercial_reg_expiry')->nullable();

            // Contact
            $table->string('contact_person', 120)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 400)->nullable();

            // Finance defaults
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('credit_limit', 15, 2)->default(0)->comment('0 = no limit set (P4.2)');
            $table->foreignId('default_receivable_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('default_revenue_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            // Workflow / status
            $table->enum('status', ['draft', 'pending_approval', 'active', 'inactive', 'rejected'])
                ->default('draft')->index();
            $table->string('rejection_reason', 255)->nullable();

            // Blocking (P4.5)
            $table->boolean('is_blocked')->default(false)->index();
            $table->string('block_reason', 255)->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customer_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('bank_name', 150);
            $table->string('account_name', 180)->comment('Beneficiary name');
            $table->string('account_number', 50)->nullable();
            $table->string('iban', 40)->nullable();
            $table->string('swift_bic', 15)->nullable();
            $table->char('currency_code', 3)->default('SAR');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false)->comment('Bank-detail changes require re-verification');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_bank_accounts');
        Schema::dropIfExists('customers');
    }
};
