<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F22 — FX revaluation runs. Records each period-end revaluation of foreign-
 * currency monetary balances to the closing rate, and the net unrealized
 * gain/loss posted to the GL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_revaluations', function (Blueprint $table): void {
            $table->id();
            $table->date('as_of');
            $table->foreignId('fx_account_id')->constrained('chart_of_accounts');
            $table->decimal('net_adjustment', 18, 2)->default(0); // + gain / − loss
            $table->string('batch_number')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_revaluations');
    }
};
