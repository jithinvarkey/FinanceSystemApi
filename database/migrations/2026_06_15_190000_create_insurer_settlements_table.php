<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.17 Slice B (§8.4) — Insurer settlement. The broker remits the net premium
 * owed to an insurer (gross less the commission incl. its VAT already withheld
 * at issuance), across one or more issued policies: draft -> approval -> post
 * (Dr insurer payable → Cr bank/IBA), then credits each policy's
 * `insurer_settled` running total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->decimal('insurer_settled', 18, 2)->default(0)->after('premium_collected')
                ->comment('Σ net premium remitted to the insurer (Slice B)');
        });

        Schema::create('insurer_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('settlement_number', 30)->unique()->comment('System, e.g. SETT-2026-000001');
            $table->foreignId('insurer_id')->constrained('vendors')->restrictOnDelete()
                ->comment('Insurer = vendor of type insurer');
            $table->date('settlement_date');
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete()
                ->comment('Credit side — a postable bank/cash (IBA) GL account');
            $table->enum('payment_method', ['bank_transfer', 'cheque', 'cash', 'online'])->default('bank_transfer');
            $table->string('reference', 100)->nullable();
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

        Schema::create('insurer_settlement_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurer_settlement_id')->constrained('insurer_settlements')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('policies')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(['insurer_settlement_id', 'policy_id'], 'sett_alloc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurer_settlement_allocations');
        Schema::dropIfExists('insurer_settlements');
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropColumn('insurer_settled');
        });
    }
};
