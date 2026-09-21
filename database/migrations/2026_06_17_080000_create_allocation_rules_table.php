<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F26 — Allocation journals. A rule spreads an amount sitting on a source
 * account/cost-centre across target cost centres by percentage (e.g. shared
 * overhead → departments). Running it posts a balanced reclassification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('source_account_id')->constrained('chart_of_accounts');
            $table->foreignId('target_account_id')->constrained('chart_of_accounts');
            $table->foreignId('source_cost_center_id')->nullable()->constrained('cost_centers');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('allocation_rule_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_rule_id')->constrained('allocation_rules')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained('cost_centers');
            $table->decimal('percentage', 8, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_rule_lines');
        Schema::dropIfExists('allocation_rules');
    }
};
