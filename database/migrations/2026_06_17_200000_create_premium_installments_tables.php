<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 (N1) — Premium installment management. A plan splits a policy's gross
 * premium into scheduled installments with due dates; each installment tracks
 * the amount due vs received so collections can be chased per due date. This is
 * a billing-schedule / tracking layer — actual cash still posts via premium
 * collections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premium_installment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->unsignedSmallInteger('installments');
            $table->string('frequency', 12);                 // monthly | quarterly | semi_annual
            $table->date('start_date');
            $table->decimal('total_amount', 18, 2);
            $table->string('status', 12)->default('active'); // active | completed | cancelled
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('premium_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('premium_installment_plans')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no');
            $table->date('due_date');
            $table->decimal('amount', 18, 2);
            $table->decimal('amount_paid', 18, 2)->default(0);
            $table->string('status', 10)->default('pending'); // pending | partial | paid
            $table->timestamps();
            $table->unique(['plan_id', 'installment_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premium_installments');
        Schema::dropIfExists('premium_installment_plans');
    }
};
