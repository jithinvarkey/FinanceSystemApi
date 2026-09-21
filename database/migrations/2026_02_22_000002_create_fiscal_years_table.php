<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-0002 — Fiscal year and period setup.
 * FIN-0040 — Period close is driven by fiscal_periods.status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique()->comment('e.g. FY2026');
            $table->string('name', 100)->comment('Display name e.g. Fiscal Year 2026');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open')->comment('Closed years reject all postings');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period_number')->comment('1-12 plus 13 for adjustment period');
            $table->string('name', 50)->comment('e.g. Mar 2026');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'soft_closed', 'closed'])->default('open')
                  ->comment('soft_closed allows adjustment journals only');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'period_number']);
            $table->index(['start_date', 'end_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
