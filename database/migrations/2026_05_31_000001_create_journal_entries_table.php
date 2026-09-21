<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-0036/0037/0038 — Manual journal entries with approval workflow.
 * Lifecycle: draft -> submitted -> approved -> posted (or rejected / reversed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('journal_number', 30)->unique();      // JV-2026-000001
            $table->date('journal_date');
            $table->string('reference', 100)->nullable();         // external doc ref
            $table->string('description', 255);
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'posted', 'reversed'])
                ->default('draft')->index();
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->foreignId('fiscal_period_id')->nullable()
                ->constrained('fiscal_periods')->restrictOnDelete();
            $table->foreignId('recurring_journal_id')->nullable()
                ->constrained('recurring_journals')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['journal_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
