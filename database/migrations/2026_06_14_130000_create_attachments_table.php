<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0.6 — Polymorphic document attachments (invoices, trade licences, receipts…).
 * Files live on a storage disk; this table is the access-controlled index with
 * a retention tag for the document-retention policy (ZATCA KSA: 6 years default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->morphs('attachable');
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size')->comment('Bytes');
            $table->string('category', 60)->nullable()->comment('e.g. invoice, trade_license, receipt');
            $table->date('retention_until')->nullable()->comment('Earliest safe-purge date');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
