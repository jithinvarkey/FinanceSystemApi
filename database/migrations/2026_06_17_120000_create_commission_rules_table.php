<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E5 — Commission engine: rules beyond a flat percentage. A rule is scoped by
 * insurer and/or product and carries a type — flat, tiered (rate varies by
 * premium band) or renewal (a distinct rate for renewed policies). The most
 * specific active rule wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('insurer_id')->nullable()->constrained('vendors');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->string('rule_type', 12)->default('flat');   // flat | tiered | renewal
            $table->decimal('rate', 8, 4)->default(0);          // flat / renewal rate (%)
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['insurer_id', 'product_id', 'is_active']);
        });

        Schema::create('commission_rule_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commission_rule_id')->constrained('commission_rules')->cascadeOnDelete();
            $table->decimal('min_premium', 18, 2)->default(0);  // applies when net premium >= this
            $table->decimal('rate', 8, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rule_tiers');
        Schema::dropIfExists('commission_rules');
    }
};
