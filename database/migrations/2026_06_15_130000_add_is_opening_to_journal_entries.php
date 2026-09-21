<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.9 — Opening balances are posted as a directly-posted journal at a cutover
 * date. This flag marks those journals so they can be listed and excluded from
 * period-activity reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->boolean('is_opening')->default(false)->after('reversal_of_id')
                ->comment('P2.9 — opening-balance / cutover journal');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropColumn('is_opening'));
    }
};
