<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0.5 — Gap-free document number sequences.
 *
 * One counter row per (document type, branch, year). branch_id/period_year use
 * 0 as the "not scoped" sentinel so the unique key is reliable (MySQL treats
 * NULLs as distinct, which would allow duplicate global counters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 60);
            $table->unsignedBigInteger('branch_id')->default(0)->comment('0 = all branches');
            $table->unsignedSmallInteger('period_year')->default(0)->comment('0 = no yearly reset');
            $table->string('prefix', 12);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->boolean('include_year')->default(true);
            $table->string('separator', 4)->default('-');
            $table->unsignedBigInteger('next_number')->default(1)->comment('Next value to issue');
            $table->timestamps();

            $table->unique(['document_type', 'branch_id', 'period_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
