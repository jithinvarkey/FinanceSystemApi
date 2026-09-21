<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E6 — Revenue recognition (IFRS 15). A schedule defers an amount of revenue and
 * recognises it over a period (e.g. a policy term). Each monthly line is earned
 * on its period date via a Dr deferred / Cr revenue posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();          // REV-2026-000001
            $table->string('name', 150);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_amount', 18, 2);
            $table->decimal('recognized_amount', 18, 2)->default(0);
            $table->foreignId('deferred_account_id')->constrained('chart_of_accounts');
            $table->foreignId('revenue_account_id')->constrained('chart_of_accounts');
            $table->string('status', 12)->default('active');  // active | completed
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('revenue_schedule_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revenue_schedule_id')->constrained('revenue_schedules')->cascadeOnDelete();
            $table->date('period_date');                    // month-end the slice is earned
            $table->decimal('amount', 18, 2);
            $table->boolean('recognized')->default(false);
            $table->string('batch_number')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_schedule_lines');
        Schema::dropIfExists('revenue_schedules');
    }
};
