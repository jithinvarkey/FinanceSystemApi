<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.17 — Installment schedule for a policy paid in installments. Each row is a
 * due amount against the gross premium; collection (later slice) settles
 * amount_collected. Generated from the policy's payment terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 18, 2);
            $table->decimal('amount_collected', 18, 2)->default(0);
            $table->enum('status', ['unpaid', 'part_paid', 'paid', 'overdue'])->default('unpaid');
            $table->timestamps();

            $table->unique(['policy_id', 'sequence'], 'policy_inst_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_installments');
    }
};
