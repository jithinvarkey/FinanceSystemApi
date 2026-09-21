<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7 — Fixed asset register + straight-line depreciation. The asset record holds
 * its cost, salvage value and useful life; a periodic depreciation run posts
 * Dr depreciation expense / Cr accumulated depreciation and logs one entry per
 * asset per month (unique, so a period can't be run twice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('asset_number', 20)->unique()->comment('System, e.g. FA-00001');
            $table->string('name', 150);
            $table->string('category', 80)->nullable();
            $table->date('acquisition_date');
            $table->decimal('cost', 18, 2);
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('depreciation_method', 20)->default('straight_line');
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->foreignId('asset_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('accum_depreciation_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->enum('status', ['active', 'fully_depreciated', 'disposed'])->default('active')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('asset_depreciation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('entry_date');
            $table->decimal('amount', 18, 2);
            $table->string('batch_number', 30)->nullable();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period_year', 'period_month'], 'asset_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_entries');
        Schema::dropIfExists('fixed_assets');
    }
};
