<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4.17 Slice E (doc §11) — Policy renewal. A renewal is a NEW policy linked back
 * to the expiring one via `renewed_from_policy_id`; the renewal commission is
 * often a distinct (lower) rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->foreignId('renewed_from_policy_id')->nullable()->after('product_id')
                ->constrained('policies')->nullOnDelete()
                ->comment('The expiring policy this one renews (Slice E)');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('renewed_from_policy_id');
        });
    }
};
