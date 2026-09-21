<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E12 — Document management with versioning.
 */
final class DocumentVersioningTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('local');

        $ap = ChartOfAccount::query()->create(['code' => '2105', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $u = User::factory()->create();
        $this->vendor = Vendor::query()->create(['vendor_code' => 'VEND-1', 'name' => 'Microteck', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'payment_terms_days' => 30, 'default_payable_account_id' => $ap, 'created_by' => $u->id]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_uploading_same_title_adds_a_new_version_and_keeps_history(): void
    {
        $this->actAs('it-supervisor');

        $first = $this->postJson('/api/v1/documents', [
            'owner_type' => 'vendor', 'owner_id' => $this->vendor->id, 'title' => 'Trade licence', 'category' => 'licence',
            'file' => UploadedFile::fake()->create('licence-2025.pdf', 40, 'application/pdf'),
        ])->assertCreated()->json('data');

        $this->assertSame(1, $first['current_version']);

        $second = $this->postJson('/api/v1/documents', [
            'owner_type' => 'vendor', 'owner_id' => $this->vendor->id, 'title' => 'Trade licence',
            'notes' => 'Renewed for 2026',
            'file' => UploadedFile::fake()->create('licence-2026.pdf', 50, 'application/pdf'),
        ])->assertCreated()->json('data');

        // Same document, bumped to v2 — not a second document.
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(2, $second['current_version']);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_versions', 2);

        $versions = $this->getJson("/api/v1/documents/{$first['id']}/versions")->assertOk()->json('data');
        $this->assertCount(2, $versions);
        $this->assertSame(2, $versions[0]['version_number']);          // newest first
        $this->assertSame('Renewed for 2026', $versions[0]['notes']);
    }

    public function test_listing_returns_documents_with_current_version(): void
    {
        $this->actAs('it-supervisor');

        $this->postJson('/api/v1/documents', [
            'owner_type' => 'vendor', 'owner_id' => $this->vendor->id, 'title' => 'Contract',
            'file' => UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'),
        ])->assertCreated();

        $list = $this->getJson('/api/v1/documents?owner_type=vendor&owner_id='.$this->vendor->id)->assertOk()->json('data');

        $this->assertCount(1, $list);
        $this->assertSame('Contract', $list[0]['title']);
        $this->assertSame('contract.pdf', $list[0]['latest_version']['file_name']);
    }

    public function test_unknown_owner_type_is_rejected(): void
    {
        $this->actAs('it-supervisor');

        $this->postJson('/api/v1/documents', [
            'owner_type' => 'spaceship', 'owner_id' => 1, 'title' => 'X',
            'file' => UploadedFile::fake()->create('x.pdf', 10),
        ])->assertStatus(422);
    }
}
