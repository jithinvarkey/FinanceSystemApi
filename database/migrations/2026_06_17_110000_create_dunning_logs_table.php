<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E4 — Smart collections: a log of dunning reminders sent to customers (email),
 * with the escalation level, so the team sees who was chased and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices');
            $table->unsignedTinyInteger('level');           // 1 reminder, 2 follow-up, 3 final demand
            $table->string('channel', 12)->default('email');
            $table->decimal('balance', 18, 2);
            $table->string('sent_to')->nullable();
            $table->foreignId('sent_by')->constrained('users');
            $table->timestamps();

            $table->index(['customer_invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_logs');
    }
};
