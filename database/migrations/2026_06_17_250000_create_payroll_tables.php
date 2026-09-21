<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll W1 — payroll finance core. A finance-side employee master + a payroll
 * run that computes earnings, GOSI and an EOSB accrual per employee and posts a
 * single balanced journal (salary expenses → GOSI payable + EOSB provision + net
 * salary payable). `payroll_settings` is a singleton mapping components to GL
 * accounts + the GOSI/EOSB rates, so nothing is hardcoded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table): void {
            $table->id();
            // GL account map (postable leaves).
            $table->foreignId('basic_salary_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('housing_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('transport_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('other_earnings_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('gosi_expense_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('gosi_payable_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('eosb_expense_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('eosb_provision_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('net_payable_account_id')->nullable()->constrained('chart_of_accounts');
            // Rates (percent) / factors.
            $table->decimal('gosi_employee_rate', 6, 3)->default(9.75);     // Saudi employee
            $table->decimal('gosi_employer_rate', 6, 3)->default(11.75);    // Saudi employer
            $table->decimal('gosi_expat_employer_rate', 6, 3)->default(2);  // expat (occupational hazard)
            $table->decimal('eosb_days_per_year', 6, 2)->default(21);       // first 5 yrs: 21 days/yr
            $table->timestamps();
        });

        Schema::create('payroll_employees', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_code', 30)->unique();
            $table->string('name', 150);
            $table->boolean('is_saudi')->default(false);                   // drives GOSI treatment
            $table->decimal('basic_salary', 18, 2)->default(0);
            $table->decimal('housing_allowance', 18, 2)->default(0);
            $table->decimal('transport_allowance', 18, 2)->default(0);
            $table->decimal('other_allowance', 18, 2)->default(0);
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->date('join_date');
            $table->date('end_date')->nullable();
            $table->string('iban', 40)->nullable();
            $table->string('status', 12)->default('active');               // active | inactive
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('pay_date');
            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('gosi_employee_total', 18, 2)->default(0);
            $table->decimal('gosi_employer_total', 18, 2)->default(0);
            $table->decimal('eosb_total', 18, 2)->default(0);
            $table->decimal('net_total', 18, 2)->default(0);
            $table->string('status', 12)->default('draft');                // draft | approved | posted | reversed
            $table->string('batch_number', 30)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['period_year', 'period_month']);
        });

        Schema::create('payroll_run_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees');
            $table->string('employee_name', 150);
            $table->decimal('basic', 18, 2)->default(0);
            $table->decimal('housing', 18, 2)->default(0);
            $table->decimal('transport', 18, 2)->default(0);
            $table->decimal('other_earnings', 18, 2)->default(0);
            $table->decimal('gross', 18, 2)->default(0);
            $table->decimal('gosi_employee', 18, 2)->default(0);
            $table->decimal('gosi_employer', 18, 2)->default(0);
            $table->decimal('eosb_accrual', 18, 2)->default(0);
            $table->decimal('other_deductions', 18, 2)->default(0);
            $table->decimal('net_pay', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_employees');
        Schema::dropIfExists('payroll_settings');
    }
};
