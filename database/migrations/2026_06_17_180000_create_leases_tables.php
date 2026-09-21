<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E14 — IFRS 16 leases. A lease recognises a right-of-use (ROU) asset and a lease
 * liability at the present value of the lease payments. Each month unwinds the
 * liability (interest + principal vs the cash payment) and depreciates the ROU
 * asset straight-line over the term.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->string('description', 180);
            $table->string('lessor', 150)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('monthly_payment', 18, 2);
            $table->decimal('discount_rate', 6, 3);          // annual %, e.g. 6.000
            $table->decimal('initial_liability', 18, 2);     // = PV of payments = ROU asset on day 1
            $table->foreignId('rou_asset_account_id')->constrained('chart_of_accounts');
            $table->foreignId('lease_liability_account_id')->constrained('chart_of_accounts');
            $table->foreignId('interest_expense_account_id')->constrained('chart_of_accounts');
            $table->foreignId('depreciation_expense_account_id')->constrained('chart_of_accounts');
            $table->foreignId('bank_account_id')->constrained('chart_of_accounts');
            $table->string('status', 12)->default('active'); // active | completed
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('lease_schedule_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->date('period_date');
            $table->decimal('opening_liability', 18, 2);
            $table->decimal('payment', 18, 2);
            $table->decimal('interest', 18, 2);
            $table->decimal('principal', 18, 2);
            $table->decimal('closing_liability', 18, 2);
            $table->decimal('rou_depreciation', 18, 2);
            $table->boolean('recognized')->default(false);
            $table->string('batch_number')->nullable();
            $table->timestamps();
            $table->unique(['lease_id', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_schedule_lines');
        Schema::dropIfExists('leases');
    }
};
