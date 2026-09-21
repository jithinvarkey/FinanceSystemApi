<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.17 Slice C (doc §10) — Policy cancellation. Splits the premium into the
 * period on risk (earned, kept by the insurer) and the cancelled period
 * (unearned, refunded to the customer), then posts a refund-direction entry:
 *   Dr insurer payable / Cr customer receivable  (unearned gross)
 *   Dr commission revenue + Dr output VAT / Cr insurer payable  (commission clawback)
 * Pro-rata refunds the full unearned slice; short-rate keeps a penalty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->string('cancellation_number', 30)->unique()->comment('System, e.g. CANC-2026-000001');
            $table->foreignId('policy_id')->constrained('policies')->restrictOnDelete();
            $table->enum('method', ['pro_rata', 'short_rate'])->default('pro_rata');
            $table->date('cancellation_date');
            $table->string('reason', 255)->nullable();
            $table->unsignedInteger('policy_days');
            $table->unsignedInteger('days_on_risk');
            $table->decimal('short_rate_penalty', 5, 2)->nullable()->comment('Penalty % withheld from the refund (short-rate only)');
            $table->decimal('refund_net', 18, 2)->default(0);
            $table->decimal('refund_premium_tax', 18, 2)->default(0);
            $table->decimal('refund_gross', 18, 2)->default(0)->comment('Total refunded to the customer');
            $table->decimal('clawback_commission', 18, 2)->default(0);
            $table->decimal('clawback_commission_tax', 18, 2)->default(0);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'posted', 'rejected', 'cancelled'])
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
        Schema::dropIfExists('policy_cancellations');
    }
};
