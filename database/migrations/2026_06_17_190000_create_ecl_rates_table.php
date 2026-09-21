<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E14 — IFRS 9 expected credit loss (ECL) provision matrix. A loss rate per AR
 * aging bucket; the ECL is exposure × loss-rate, summed across buckets. Seeded
 * with a conservative default matrix (editable later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecl_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('bucket', 12)->unique();      // current | 1_30 | 31_60 | 61_90 | 91_120 | 120_plus
            $table->decimal('loss_rate', 6, 3);          // percent, e.g. 2.500
            $table->timestamps();
        });

        $now = now();
        DB::table('ecl_rates')->insert([
            ['bucket' => 'current', 'loss_rate' => 0.5, 'created_at' => $now, 'updated_at' => $now],
            ['bucket' => '1_30', 'loss_rate' => 2.0, 'created_at' => $now, 'updated_at' => $now],
            ['bucket' => '31_60', 'loss_rate' => 5.0, 'created_at' => $now, 'updated_at' => $now],
            ['bucket' => '61_90', 'loss_rate' => 15.0, 'created_at' => $now, 'updated_at' => $now],
            ['bucket' => '91_120', 'loss_rate' => 35.0, 'created_at' => $now, 'updated_at' => $now],
            ['bucket' => '120_plus', 'loss_rate' => 70.0, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ecl_rates');
    }
};
