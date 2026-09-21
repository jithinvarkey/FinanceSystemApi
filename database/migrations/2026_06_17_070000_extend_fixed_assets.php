<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F23/F24/F25 — Fixed-asset extensions: a CWIP (capital work-in-progress) flag
 * (such assets don't depreciate until capitalised), and a disposals table
 * recording the sale/scrap of an asset and its gain or loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->boolean('is_cwip')->default(false)->after('depreciation_method');
        });

        Schema::create('asset_disposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
            $table->date('disposal_date');
            $table->decimal('proceeds', 18, 2)->default(0);
            $table->decimal('book_value', 18, 2);
            $table->decimal('gain_loss', 18, 2);     // + gain / − loss
            $table->string('method', 20)->default('sale'); // sale | scrap
            $table->string('batch_number')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_disposals');
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->dropColumn('is_cwip');
        });
    }
};
