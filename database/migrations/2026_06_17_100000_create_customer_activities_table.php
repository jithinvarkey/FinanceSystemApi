<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E2 — Customer 360: collection notes & communication history. A free-text
 * activity log against a customer (calls, emails, meetings, notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('activity_type', 20)->default('note'); // note | call | email | meeting
            $table->text('note');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activities');
    }
};
