<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F16 — Seller identity for ZATCA e-invoicing (and report headers): the broker's
 * legal name, VAT registration number (15-digit), CR number and address. A
 * single-row settings table; a default row is seeded so the QR endpoint always
 * has a seller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('company_name');
            $table->string('vat_number', 15)->nullable();      // ZATCA VAT registration number
            $table->string('commercial_reg_no')->nullable();   // CR number
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        DB::table('company_settings')->insert([
            'company_name' => 'Diamond Insurance Broker',
            'vat_number' => '300000000000003',
            'commercial_reg_no' => null,
            'address' => '7356 Abdul Aziz Al Uraifi - Ar Rabi, Unit 1316',
            'city' => 'Riyadh',
            'postal_code' => '13315',
            'phone' => '920004778',
            'email' => 'finance@dbroker.com.sa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
