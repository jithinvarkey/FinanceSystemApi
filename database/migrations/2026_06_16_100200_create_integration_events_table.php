<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound integration (P-INT) — the sync log. One row per PUSHed event:
 * processed (a draft was created/updated), duplicate (already ingested, no-op),
 * or failed (validation / business-rule error, with the message). The raw
 * payload is kept for replay/forensics. target_* points at the resulting
 * Policy / PolicyEndorsement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_client_id')->nullable()->constrained('integration_clients')->nullOnDelete();
            $table->string('source_system', 40);
            $table->string('event_type', 30)->comment('policy | endorsement | renewal');
            $table->string('external_id', 80)->nullable();
            $table->string('status', 20)->comment('processed | duplicate | failed');
            $table->nullableMorphs('target');
            $table->string('message', 500)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['source_system', 'event_type', 'external_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
