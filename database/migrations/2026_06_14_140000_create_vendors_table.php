<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.1–P3.5 — Vendor master with approval workflow, compliance (TRN / trade
 * licence + expiry), bank details, and blocking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('vendor_code', 30)->unique()->comment('Generated, e.g. VEND-2026-000001');
            $table->string('name', 180)->comment('Legal name');
            $table->string('trade_name', 180)->nullable();
            $table->enum('vendor_type', ['supplier', 'service_provider', 'insurer', 'contractor', 'other'])
                ->default('supplier');

            // Compliance (P3.3)
            $table->string('trn', 20)->nullable()->comment('Saudi VAT registration number, 15 digits');
            $table->string('trade_license_no', 60)->nullable();
            $table->date('trade_license_expiry')->nullable();

            // Contact
            $table->string('contact_person', 120)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 400)->nullable();

            // Finance defaults
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->char('currency_code', 3)->default('SAR');
            $table->foreignId('default_payable_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('default_expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            // Workflow / status
            $table->enum('status', ['draft', 'pending_approval', 'active', 'inactive', 'rejected'])
                ->default('draft')->index();
            $table->string('rejection_reason', 255)->nullable();

            // Blocking (P3.5)
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

        Schema::create('vendor_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
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
        Schema::dropIfExists('vendor_bank_accounts');
        Schema::dropIfExists('vendors');
    }
};
