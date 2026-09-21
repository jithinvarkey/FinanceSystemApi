<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll W5 — salary grades. A grade defines a salary band (min/mid/max). An
 * employee may be assigned a grade; their basic salary should sit within it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_grades', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->decimal('min_salary', 18, 2)->default(0);
            $table->decimal('mid_salary', 18, 2)->default(0);
            $table->decimal('max_salary', 18, 2)->default(0);
            $table->string('status', 12)->default('active');
            $table->timestamps();
        });

        Schema::table('payroll_employees', function (Blueprint $table): void {
            $table->foreignId('salary_grade_id')->nullable()->after('cost_center_id')->constrained('salary_grades')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('salary_grade_id');
        });
        Schema::dropIfExists('salary_grades');
    }
};
