<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 — Bank reconciliation. A reconciliation reconciles a bank GL account's
 * book balance to a bank statement at a date: the operator clears the GL
 * transactions that appear on the statement; the cleared total must equal the
 * statement balance. Cleared transactions are tracked in a link table so the
 * immutable `gl_transactions` ledger is never mutated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 2);
            $table->decimal('opening_balance', 18, 2)->default(0)->comment('Last completed reconciliation balance');
            $table->decimal('cleared_total', 18, 2)->default(0)->comment('Opening + cleared movement this period');
            $table->decimal('difference', 18, 2)->default(0)->comment('statement_balance − cleared_total (0 when reconciled)');
            $table->enum('status', ['completed'])->default('completed');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'statement_date']);
        });

        Schema::create('bank_reconciliation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('gl_transaction_id')->constrained('gl_transactions')->restrictOnDelete();
            $table->timestamps();

            // A GL transaction can be cleared by at most one reconciliation.
            $table->unique('gl_transaction_id', 'bank_recon_txn_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
