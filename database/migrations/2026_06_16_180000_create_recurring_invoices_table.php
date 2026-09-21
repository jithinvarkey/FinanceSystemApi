<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring customer invoices & vendor bills. A template (party + lines +
 * schedule) that materialises a DRAFT invoice/bill each period via the existing
 * invoice services; the draft then follows the normal approve → post flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['customer', 'vendor'])->index();
            $table->unsignedBigInteger('party_id')->comment('customer_id or vendor_id');
            $table->string('name', 150);
            $table->enum('frequency', ['monthly', 'quarterly', 'yearly']);
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->date('start_date');
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('reference', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->unsignedTinyInteger('due_days')->default(30);
            $table->json('lines');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('last_generated_at')->nullable();
            $table->unsignedInteger('generated_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
