<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.17 — Policy endorsements (mid-term changes). Four financial types:
 * addition & upgrade raise an ADDITIONAL premium; deletion & downgrade raise a
 * REFUND premium. On post, each writes a delta of the policy's fiduciary entry
 * and adjusts the policy's running totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_endorsements', function (Blueprint $table): void {
            $table->id();
            $table->string('endorsement_number', 30)->unique()->comment('System, e.g. END-2026-000001');
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->enum('type', ['addition', 'deletion', 'upgrade', 'downgrade']);
            $table->enum('direction', ['additional', 'refund'])->comment('Derived from type');
            $table->date('effective_date');
            $table->string('reason', 255)->nullable();

            // Signed magnitudes are stored positive; `direction` gives the sign.
            $table->decimal('delta_net_premium', 18, 2)->default(0);
            $table->decimal('delta_premium_tax', 18, 2)->default(0);
            $table->decimal('delta_gross', 18, 2)->default(0);
            $table->decimal('delta_commission', 18, 2)->default(0);
            $table->decimal('delta_commission_tax', 18, 2)->default(0);

            $table->enum('status', ['draft', 'pending_approval', 'approved', 'posted', 'rejected'])
                ->default('draft')->index();
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->string('batch_number', 30)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_endorsements');
    }
};
