<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.17 Slice B (§8.3) — Premium collection. The broker collects the gross
 * premium from the customer (as collection agent / fiduciary): draft -> approval
 * -> post (Dr bank/IBA → Cr customer receivable), then credits the policy's
 * `premium_collected` running total and each settled installment's
 * `amount_collected`. Installment policies allocate to specific installments;
 * full-payment policies collect against the policy directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->decimal('premium_collected', 18, 2)->default(0)->after('commission_tax_amount')
                ->comment('Σ premium collected from the customer (Slice B)');
        });

        Schema::create('premium_collections', function (Blueprint $table): void {
            $table->id();
            $table->string('collection_number', 30)->unique()->comment('System, e.g. PCOL-2026-000001');
            $table->foreignId('policy_id')->constrained('policies')->restrictOnDelete();
            $table->date('collection_date');
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Debit side — a postable bank/cash (IBA) GL account');
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

        Schema::create('premium_collection_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('premium_collection_id')->constrained('premium_collections')->cascadeOnDelete();
            $table->foreignId('policy_installment_id')->constrained('policy_installments')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['premium_collection_id', 'policy_installment_id'], 'pcol_alloc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_collection_allocations');
        Schema::dropIfExists('premium_collections');
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn('premium_collected');
        });
    }
};
