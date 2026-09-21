<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0.4 — Generic approval-workflow engine.
 *
 * A workflow is defined per document type as an ordered set of steps; each
 * step demands a permission and applies above an amount threshold. A request
 * is one workflow instance running against a polymorphic document, advancing
 * step-by-step as approvers act. Reused by journals, AP, AR, payments, budgets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 60)->unique()->comment('e.g. journal_entry, vendor_payment');
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence')->comment('1-based order of the step');
            $table->string('name', 120);
            $table->string('required_permission', 80)->comment('Ability an actor must hold to clear this step');
            $table->decimal('min_amount', 18, 2)->default(0)->comment('Step applies only when amount >= this');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['approval_workflow_id', 'sequence']);
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->morphs('approvable');
            $table->foreignId('approval_workflow_id')->constrained('approval_workflows')->restrictOnDelete();
            $table->string('document_type', 60)->index();
            $table->decimal('amount', 18, 2)->default(0)->comment('Drives threshold routing');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending')->index();
            $table->unsignedTinyInteger('current_sequence')->nullable()->comment('Step awaiting action; null once resolved');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignId('approval_step_id')->nullable()->constrained('approval_steps')->nullOnDelete();
            $table->unsignedTinyInteger('sequence')->comment('Step sequence this action cleared');
            $table->enum('action', ['approved', 'rejected']);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('comment', 500)->nullable();
            $table->timestamp('acted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
