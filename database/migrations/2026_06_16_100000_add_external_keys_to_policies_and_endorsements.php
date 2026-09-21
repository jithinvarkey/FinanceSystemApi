<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound integration (P-INT) — idempotency keys. Policy/endorsement data is
 * mastered upstream and PUSHed in; these columns let the ingest layer upsert by
 * the source system's own id (a record from "broker-core" with external_id
 * "POL-99" maps to exactly one Diamond policy). NULL on manually-created
 * records. Composite unique [source_system, external_id] enforces "ingest once".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->string('source_system', 40)->nullable()->after('policy_number')->comment('Upstream system that pushed this record');
            $table->string('external_id', 80)->nullable()->after('source_system')->comment("Upstream's own id for idempotent upsert");
            $table->unique(['source_system', 'external_id'], 'policies_source_external_unique');
        });

        Schema::table('policy_endorsements', function (Blueprint $table): void {
            $table->string('source_system', 40)->nullable()->after('endorsement_number');
            $table->string('external_id', 80)->nullable()->after('source_system');
            $table->unique(['source_system', 'external_id'], 'endorsements_source_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table): void {
            $table->dropUnique('policies_source_external_unique');
            $table->dropColumn(['source_system', 'external_id']);
        });

        Schema::table('policy_endorsements', function (Blueprint $table): void {
            $table->dropUnique('endorsements_source_external_unique');
            $table->dropColumn(['source_system', 'external_id']);
        });
    }
};
