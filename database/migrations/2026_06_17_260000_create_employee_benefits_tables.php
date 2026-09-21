<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll W2 — employee benefits. Air-ticket entitlements accrue a monthly
 * provision (Dr expense / Cr provision); medical / visa / iqama costs are
 * recorded as they occur. Adds benefit GL accounts to payroll_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table): void {
            $table->foreignId('airticket_expense_account_id')->nullable()->after('net_payable_account_id')->constrained('chart_of_accounts');
            $table->foreignId('airticket_provision_account_id')->nullable()->after('airticket_expense_account_id')->constrained('chart_of_accounts');
            $table->foreignId('benefit_expense_account_id')->nullable()->after('airticket_provision_account_id')->constrained('chart_of_accounts');
            $table->foreignId('benefit_payable_account_id')->nullable()->after('benefit_expense_account_id')->constrained('chart_of_accounts');
        });

        Schema::create('employee_benefits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees')->cascadeOnDelete();
            $table->string('benefit_type', 16);             // air_ticket | medical | visa | iqama | other
            $table->string('description', 180)->nullable();
            $table->decimal('annual_amount', 18, 2)->default(0);
            $table->decimal('accrued_amount', 18, 2)->default(0);   // provision accrued to date (air ticket)
            $table->decimal('utilized_amount', 18, 2)->default(0);  // actual cost incurred to date
            $table->date('renewal_date')->nullable();
            $table->string('status', 12)->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('benefit_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_benefit_id')->constrained('employee_benefits')->cascadeOnDelete();
            $table->date('cost_date');
            $table->decimal('amount', 18, 2);
            $table->string('kind', 12);                     // accrual | utilization | expense
            $table->string('description', 200)->nullable();
            $table->string('batch_number', 30)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_costs');
        Schema::dropIfExists('employee_benefits');
        Schema::table('payroll_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('airticket_expense_account_id');
            $table->dropConstrainedForeignId('airticket_provision_account_id');
            $table->dropConstrainedForeignId('benefit_expense_account_id');
            $table->dropConstrainedForeignId('benefit_payable_account_id');
        });
    }
};
