<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll W3 — employee loans & salary advances. Disbursement posts Dr loan
 * receivable / Cr bank; the monthly instalment is recovered through payroll
 * (a deduction on the run that credits the loan receivable on posting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table): void {
            $table->foreignId('loan_receivable_account_id')->nullable()->after('benefit_payable_account_id')->constrained('chart_of_accounts');
            $table->foreignId('loan_bank_account_id')->nullable()->after('loan_receivable_account_id')->constrained('chart_of_accounts');
        });

        Schema::create('employee_loans', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees')->cascadeOnDelete();
            $table->string('loan_type', 10);                // loan | advance
            $table->decimal('principal', 18, 2);
            $table->decimal('monthly_installment', 18, 2);
            $table->decimal('outstanding_balance', 18, 2);
            $table->date('disbursed_date');
            $table->string('status', 10)->default('active'); // active | settled
            $table->string('batch_number', 30)->nullable();
            $table->string('notes', 200)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('loan_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_loan_id')->constrained('employee_loans')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->boolean('applied')->default(false);     // true once the run is posted
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_recoveries');
        Schema::dropIfExists('employee_loans');
        Schema::table('payroll_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('loan_receivable_account_id');
            $table->dropConstrainedForeignId('loan_bank_account_id');
        });
    }
};
