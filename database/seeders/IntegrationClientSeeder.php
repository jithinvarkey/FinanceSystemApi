<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Inbound integration (P-INT) — a dev client + the service user its ingested
 * drafts are attributed to. The dev key below is fixed so local curl/tests can
 * authenticate; in production, mint a random key and store only its hash.
 */
final class IntegrationClientSeeder extends Seeder
{
    /** Fixed dev key — present it in the X-Integration-Key header. */
    public const DEV_KEY = 'diamond-dev-integration-key-2026';

    public function run(): void
    {
        $serviceUser = User::query()->firstOrCreate(
            ['email' => 'integration@diamond.local'],
            ['name' => 'Integration Service', 'password' => Hash::make(bin2hex(random_bytes(16)))],
        );

        IntegrationClient::query()->updateOrCreate(
            ['source_system' => 'broker-core'],
            [
                'name' => 'Broker Core (upstream policy admin)',
                'key_hash' => IntegrationClient::hashKey(self::DEV_KEY),
                'acts_as_user_id' => $serviceUser->id,
                'is_active' => true,
            ],
        );
    }
}
