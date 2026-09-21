<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-0039 — Recurring journal templates. The scheduler materialises each
 * occurrence as a DRAFT journal entry; normal approval flow then applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_journals', function (Blueprint $table): void {
            $table->id();
            $table->string('template_code', 30)->unique();        // RJ-000001
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->enum('frequency', ['monthly', 'quarterly', 'yearly']);
            $table->unsignedTinyInteger('day_of_month')->default(1); // 1..28
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_run_date')->index();
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->char('currency_code', 3)->default('SAR');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('recurring_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recurring_journal_id')
                ->constrained('recurring_journals')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('cost_centers')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['recurring_journal_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_journal_lines');
        Schema::dropIfExists('recurring_journals');
    }
};
