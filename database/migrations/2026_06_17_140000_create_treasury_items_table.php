<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E7 — Treasury / cash forecasting. A treasury item is a planned cash movement
 * (a known future receipt or payment) that is NOT yet captured as an AR/AP
 * document — e.g. an expected loan drawdown, a planned dividend, a salary run.
 * The cash-flow forecast layers these on top of AR-due / AP-due projections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_items', function (Blueprint $table): void {
            $table->id();
            $table->string('description', 200);
            $table->string('direction', 3);            // in | out
            $table->decimal('amount', 18, 2);
            $table->date('expected_date');
            $table->string('status', 10)->default('planned');  // planned | cleared
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_items');
    }
};
