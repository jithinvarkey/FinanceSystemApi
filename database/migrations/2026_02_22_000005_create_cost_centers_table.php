<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-0005 — Cost center setup, hierarchical for departmental rollups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique()->comment('e.g. CC-BROKING');
            $table->string('name', 120);
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->comment('Responsible manager');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
