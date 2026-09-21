<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F20 — Imported bank statement lines. The operator imports the bank's statement
 * (CSV); each line carries a signed amount (+ money in / − money out) and can be
 * matched to a GL transaction on the same bank account to drive reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts');
            $table->date('txn_date');
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('amount', 18, 2);                 // signed: + in / − out
            $table->decimal('balance', 18, 2)->nullable();
            $table->foreignId('matched_gl_transaction_id')->nullable()->constrained('gl_transactions');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['bank_account_id', 'txn_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
