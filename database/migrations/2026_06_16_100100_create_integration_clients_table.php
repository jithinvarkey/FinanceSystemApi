<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound integration (P-INT) — machine clients. Each upstream system that
 * PUSHes events authenticates with an API key (presented as X-Integration-Key);
 * we store only its sha256 hash. `acts_as_user_id` is the maker the ingested
 * drafts are attributed to (created_by), so the audit trail and maker-checker
 * SoD stay intact — the client raises drafts, a human approves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('source_system', 40)->unique()->comment('Stamped onto ingested records');
            $table->string('key_hash', 64)->unique()->comment('sha256 of the raw API key');
            $table->foreignId('acts_as_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_clients');
    }
};
