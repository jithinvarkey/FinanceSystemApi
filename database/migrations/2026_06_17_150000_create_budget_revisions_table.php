<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E9 — Budget revisions & transfers. Every change to a budget line's annual
 * amount is logged here: a 'revision' restates a single line, a 'transfer' is a
 * virement moving budget between two lines (one negative + one positive row,
 * cross-linked by counterpart_line_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('budget_line_id')->constrained('budget_lines')->cascadeOnDelete();
            $table->string('type', 10);                 // revision | transfer
            $table->foreignId('counterpart_line_id')->nullable()->constrained('budget_lines')->nullOnDelete();
            $table->decimal('delta', 18, 2);            // signed change applied to budget_line_id
            $table->decimal('new_amount', 18, 2);       // line's annual_amount after the change
            $table->string('reason', 250);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_revisions');
    }
};
