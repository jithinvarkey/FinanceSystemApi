<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll W6 — run types. Adds run_type (regular / off_cycle / final_settlement)
 * and drops the one-run-per-month unique key (off-cycle bonus runs and final
 * settlements happen in addition to the regular monthly run; the "one regular
 * run per period" rule is now enforced in PayrollService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->string('run_type', 16)->default('regular')->after('reference');
            $table->foreignId('payroll_employee_id')->nullable()->after('run_type')->constrained('payroll_employees')->nullOnDelete();
            $table->dropUnique(['period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payroll_employee_id');
            $table->dropColumn('run_type');
            $table->unique(['period_year', 'period_month']);
        });
    }
};
