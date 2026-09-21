<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (N4) — Self-service reporting. A saved report definition captures the
 * dimensions, measure and filters a user composed; ReportBuilderService runs it
 * against the GL and supports drill-through to the underlying transaction lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 250)->nullable();
            $table->string('measure', 16);               // net | debit | credit
            $table->string('group_by', 120);             // csv of dimensions, ordered
            $table->json('filters')->nullable();         // {from, to, account_type}
            $table->boolean('is_shared')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
