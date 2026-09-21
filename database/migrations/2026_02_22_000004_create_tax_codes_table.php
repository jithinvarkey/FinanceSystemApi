<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-0004 — Tax configuration (VAT rules) with GL account mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique()->comment('e.g. VAT5, EXEMPT');
            $table->string('name', 100);
            $table->enum('tax_type', ['input', 'output', 'both'])->comment('input = purchases, output = sales');
            $table->decimal('rate', 8, 4)->comment('Percentage e.g. 5.0000');
            $table->foreignId('input_account_id')->nullable()->constrained('chart_of_accounts')
                  ->comment('GL account for recoverable input VAT');
            $table->foreignId('output_account_id')->nullable()->constrained('chart_of_accounts')
                  ->comment('GL account for output VAT payable');
            $table->boolean('is_recoverable')->default(true);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
