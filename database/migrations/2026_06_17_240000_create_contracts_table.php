<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (N5) — Contract & incentive register. Tracks agreements (commission /
 * service / incentive / lease) with a counterparty, their value, term and
 * status, with expiry tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_number', 30)->unique();
            $table->string('title', 180);
            $table->string('party_type', 12);            // customer | vendor | insurer | other
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('party_name', 180)->nullable();
            $table->string('category', 16);              // commission | service | incentive | lease | other
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('value', 18, 2)->default(0);
            $table->string('status', 12)->default('active'); // active | expired | terminated
            $table->string('notes', 250)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
