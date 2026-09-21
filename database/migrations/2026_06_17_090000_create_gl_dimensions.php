<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F27 — Extra GL analysis dimensions (e.g. project, segment). A second analysis
 * axis alongside the cost centre: every GL line — and the journal lines that
 * produce them — can carry a dimension for slice-and-dice reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_dimensions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 30)->default('project'); // project | segment | …
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('status', 12)->default('active');
            $table->timestamps();

            $table->unique(['type', 'code']);
            $table->index('type');
        });

        Schema::table('gl_transactions', function (Blueprint $table): void {
            $table->foreignId('dimension_id')->nullable()->after('cost_center_id')->constrained('gl_dimensions');
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->foreignId('dimension_id')->nullable()->after('cost_center_id')->constrained('gl_dimensions');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('dimension_id'));
        Schema::table('gl_transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('dimension_id'));
        Schema::dropIfExists('gl_dimensions');
    }
};
