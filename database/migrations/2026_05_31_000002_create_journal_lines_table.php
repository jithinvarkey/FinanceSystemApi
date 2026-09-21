<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-0036 — Journal entry lines. Each line is one side of the double entry.
 * Exactly one of debit/credit is non-zero per line (enforced in service layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('cost_centers')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->char('currency_code', 3)->default('SAR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->timestamps();

            $table->unique(['journal_entry_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
