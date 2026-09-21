<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E12 — Document management with versioning. A `document` is a titled file
 * attached polymorphically to any entity (vendor, customer, asset, policy,
 * invoice…). Each upload that reuses an existing title adds a new
 * `document_version` and bumps current_version, preserving the full history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->morphs('documentable');                 // documentable_type + documentable_id
            $table->string('title', 180);
            $table->string('category', 60)->nullable();      // contract | invoice | licence | id | other
            $table->unsignedInteger('current_version')->default(1);
            $table->string('status', 10)->default('active'); // active | archived
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['documentable_type', 'documentable_id', 'title']);
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('file_name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('storage_path', 255);
            $table->string('notes', 250)->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->unique(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
