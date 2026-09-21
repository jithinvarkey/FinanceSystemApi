<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GL Posting Core — central double-entry ledger.
 * Every subledger (AP, AR, Assets, Bank, Journals) posts here through GlPostingService.
 * Rows are immutable once written; corrections are made by reversal postings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_number', 30)->comment('Groups the balanced set of lines for one posting');
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods');
            $table->date('transaction_date');
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->char('currency_code', 3)->comment('Original document currency');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_debit', 18, 2)->default(0)->comment('Debit converted to base currency');
            $table->decimal('base_credit', 18, 2)->default(0)->comment('Credit converted to base currency');
            $table->string('source_type', 60)->comment('Originating model class e.g. App\\Models\\JournalEntry');
            $table->unsignedBigInteger('source_id')->comment('Originating document id');
            $table->string('description', 255)->nullable();
            $table->boolean('is_reversal')->default(false);
            $table->foreignId('posted_by')->constrained('users');
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index('batch_number');
            $table->index(['account_id', 'transaction_date']);
            $table->index(['source_type', 'source_id']);
            $table->index(['fiscal_period_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_transactions');
    }
};
