<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-0001 — Chart of accounts setup.
 * Hierarchical account structure with type-driven normal balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique()->comment('Account code e.g. 1100-01');
            $table->string('name', 150)->comment('Account display name');
            $table->enum('account_type', ['asset', 'liability', 'equity', 'revenue', 'expense'])
                  ->comment('Primary financial statement classification');
            $table->enum('normal_balance', ['debit', 'credit'])->comment('Side that increases the account');
            $table->foreignId('parent_id')->nullable()
                  ->constrained('chart_of_accounts')->nullOnDelete()
                  ->comment('Parent account for hierarchy rollup');
            $table->unsignedTinyInteger('level')->default(1)->comment('Depth in account tree, 1 = root');
            $table->boolean('is_postable')->default(true)->comment('Only leaf accounts accept postings');
            $table->boolean('is_bank_account')->default(false)->comment('Flags GL accounts linked to bank accounts');
            $table->boolean('is_control_account')->default(false)->comment('AP/AR control — blocks manual journals');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_type', 'status']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
