<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll W8 — workforce budget. One budgeted amount per category per year,
 * compared against the actuals computed from posted payroll runs & benefit costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_budgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('category', 12);              // salary | gosi | eosb | bonus | benefit | total
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['year', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_budgets');
    }
};
