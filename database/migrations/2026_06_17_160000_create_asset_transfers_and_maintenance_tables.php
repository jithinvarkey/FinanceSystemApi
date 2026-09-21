<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E10 — Fixed asset transfer & maintenance. Adds location/custodian tracking to
 * the asset register, an audit trail of transfers (cost centre / location /
 * custodian moves), and a maintenance log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->string('location', 120)->nullable()->after('category');
            $table->string('custodian', 120)->nullable()->after('location');
        });

        Schema::create('asset_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('transfer_date');
            $table->foreignId('from_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('to_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('from_location', 120)->nullable();
            $table->string('to_location', 120)->nullable();
            $table->string('from_custodian', 120)->nullable();
            $table->string('to_custodian', 120)->nullable();
            $table->string('reason', 250);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('asset_maintenance_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('maintenance_date');
            $table->string('type', 20);                 // preventive | corrective | inspection
            $table->string('description', 250);
            $table->decimal('cost', 18, 2)->default(0);
            $table->string('vendor', 120)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_logs');
        Schema::dropIfExists('asset_transfers');
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->dropColumn(['location', 'custodian']);
        });
    }
};
