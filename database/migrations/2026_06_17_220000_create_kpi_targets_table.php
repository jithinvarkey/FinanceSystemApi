<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (N3) — KPI engine. This table is both the KPI catalog/library and the
 * editable target store; the actual value is computed at runtime from the GL and
 * sub-ledgers by KpiService. `audiences` is a CSV of role scorecards the KPI
 * appears on (cfo / ceo / board).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_targets', function (Blueprint $table): void {
            $table->id();
            $table->string('kpi_key', 40)->unique();
            $table->string('name', 120);
            $table->string('category', 40);              // liquidity | profitability | receivables | payables | growth
            $table->string('unit', 12);                  // currency | percent | number | days
            $table->string('direction', 16);             // higher_better | lower_better
            $table->decimal('target', 18, 2)->nullable();
            $table->string('audiences', 60)->default(''); // csv: cfo,ceo,board
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['cash_position', 'Cash position', 'liquidity', 'currency', 'higher_better', 100000, 'cfo,ceo,board', 1],
            ['current_ratio', 'Current ratio', 'liquidity', 'number', 'higher_better', 1.5, 'cfo,board', 2],
            ['ar_outstanding', 'AR outstanding', 'receivables', 'currency', 'lower_better', 50000, 'cfo,ceo', 3],
            ['overdue_ar_pct', 'Overdue AR %', 'receivables', 'percent', 'lower_better', 15, 'cfo,ceo,board', 4],
            ['collection_ratio', 'Collection ratio', 'receivables', 'percent', 'higher_better', 85, 'cfo,ceo', 5],
            ['ap_outstanding', 'AP outstanding', 'payables', 'currency', 'lower_better', 40000, 'cfo', 6],
            ['revenue_ytd', 'Revenue YTD', 'growth', 'currency', 'higher_better', null, 'ceo,board', 7],
            ['net_profit_ytd', 'Net profit YTD', 'profitability', 'currency', 'higher_better', null, 'cfo,ceo,board', 8],
            ['gross_margin_pct', 'Net margin %', 'profitability', 'percent', 'higher_better', 20, 'cfo,ceo,board', 9],
            ['expense_ratio_pct', 'Expense ratio %', 'profitability', 'percent', 'lower_better', 70, 'cfo', 10],
        ];
        foreach ($rows as [$key, $name, $cat, $unit, $dir, $target, $aud, $ord]) {
            DB::table('kpi_targets')->insert([
                'kpi_key' => $key, 'name' => $name, 'category' => $cat, 'unit' => $unit, 'direction' => $dir,
                'target' => $target, 'audiences' => $aud, 'display_order' => $ord, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_targets');
    }
};
