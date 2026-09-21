<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F15 — VAT filing workflow. A filed VAT return for a tax period: the computed
 * figures are snapshotted at filing time (so they stay tied out even if a
 * back-dated transaction changes later), the period is locked, and the net is
 * settled to ZATCA. One filed/paid return may not overlap another's period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();          // VATR-2026-000001
            $table->date('period_from');
            $table->date('period_to');

            // Snapshot of the return at filing time.
            $table->decimal('output_vat', 18, 2)->default(0);
            $table->decimal('input_vat', 18, 2)->default(0);
            $table->decimal('reverse_charge_base', 18, 2)->default(0);
            $table->decimal('reverse_charge_vat', 18, 2)->default(0);
            $table->decimal('net_vat_payable', 18, 2)->default(0);

            $table->string('status')->default('draft');     // draft | filed | paid
            $table->string('zatca_reference')->nullable();  // ZATCA submission ref
            $table->text('notes')->nullable();

            $table->timestamp('filed_at')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->string('payment_batch_number')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['period_from', 'period_to']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_returns');
    }
};
