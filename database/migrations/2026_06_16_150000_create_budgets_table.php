<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 — Budgeting. A budget belongs to a fiscal year and holds an annual target
 * per GL account; budget-vs-actual compares those targets to the account's
 * actual movement in the GL for the year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->decimal('annual_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['budget_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
