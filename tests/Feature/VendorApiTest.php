<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P3.1–P3.5 — Vendor master: draft -> approval -> active, blocking, bank details.
 */
final class VendorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function createVendor(array $overrides = []): array
    {
        return $this->postJson('/api/v1/accounts-payable/vendors', array_merge([
            'name' => 'Acme Trading LLC',
            'vendor_type' => 'supplier',
            'trn' => '100123456700003',
            'payment_terms_days' => 30,
        ], $overrides))->json('data');
    }

    public function test_maker_creates_draft_vendor_with_generated_code(): void
    {
        $this->actingAsRole('accountant');

        $vendor = $this->createVendor();

        $this->assertSame('draft', $vendor['status']);
        $this->assertStringStartsWith('VEND-', $vendor['vendor_code']);
        $this->assertFalse($vendor['is_transactable']);
    }

    public function test_invalid_trn_is_rejected(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-payable/vendors', [
            'name' => 'Bad TRN Co', 'vendor_type' => 'supplier', 'trn' => '123',
        ])->assertStatus(422)->assertJsonValidationErrors('trn');
    }

    public function test_submit_raises_approval_and_marks_pending(): void
    {
        $this->actingAsRole('accountant');
        $vendor = $this->createVendor();

        $response = $this->postJson("/api/v1/accounts-payable/vendors/{$vendor['id']}/submit");
        $response->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $this->assertDatabaseHas('approval_requests', [
            'approvable_type' => Vendor::class,
            'approvable_id' => $vendor['id'],
            'document_type' => 'vendor',
            'status' => 'pending',
        ]);
    }

    public function test_approval_activates_vendor(): void
    {
        $maker = $this->actingAsRole('accountant');
        $vendor = $this->createVendor();
        $this->postJson("/api/v1/accounts-payable/vendors/{$vendor['id']}/submit")->assertOk();

        $requestId = ApprovalRequest::query()
            ->where('approvable_type', Vendor::class)
            ->where('approvable_id', $vendor['id'])
            ->value('id');

        // A different user with the AP-approve permission clears it.
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fresh = Vendor::query()->find($vendor['id']);
        $this->assertSame('active', $fresh->status->value);
        $this->assertNotNull($fresh->approved_at);
        $this->assertTrue($fresh->isTransactable());
    }

    public function test_block_requires_reason_and_stops_transactability(): void
    {
        $this->actingAsRole('accountant');
        $vendor = $this->createVendor();

        $this->postJson("/api/v1/accounts-payable/vendors/{$vendor['id']}/block", [])
            ->assertStatus(422);

        $blocked = $this->postJson("/api/v1/accounts-payable/vendors/{$vendor['id']}/block", [
            'reason' => 'Trade licence expired',
        ])->assertOk()->json('data');

        $this->assertTrue($blocked['is_blocked']);
        $this->assertFalse($blocked['is_transactable']);

        $unblocked = $this->postJson("/api/v1/accounts-payable/vendors/{$vendor['id']}/unblock")
            ->assertOk()->json('data');
        $this->assertFalse($unblocked['is_blocked']);
    }

    public function test_bank_account_is_unverified_until_a_checker_verifies(): void
    {
        $this->actingAsRole('accountant');
        $vendor = $this->createVendor();

        $account = $this->postJson("/api/v1/accounts-payable/vendors/{$vendor['id']}/bank-accounts", [
            'bank_name' => 'Emirates NBD',
            'account_name' => 'Acme Trading LLC',
            'iban' => 'AE070331234567890123456',
            'is_primary' => true,
        ])->assertCreated()->json('data');

        $this->assertFalse($account['is_verified']);

        // Maker cannot verify (no approve permission).
        $this->postJson("/api/v1/accounts-payable/bank-accounts/{$account['id']}/verify")
            ->assertStatus(403);

        // Checker verifies.
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/accounts-payable/bank-accounts/{$account['id']}/verify")
            ->assertOk()->assertJsonPath('data.is_verified', true);
    }

    public function test_bank_account_requires_iban_or_account_number(): void
    {
        $this->actingAsRole('accountant');
        $vendor = $this->createVendor();

        $this->postJson("/api/v1/accounts-payable/vendors/{$vendor['id']}/bank-accounts", [
            'bank_name' => 'Some Bank',
            'account_name' => 'Acme',
        ])->assertStatus(422);
    }

    public function test_active_vendor_is_not_editable(): void
    {
        $maker = $this->actingAsRole('accountant');
        $vendor = $this->createVendor();
        $this->postJson("/api/v1/accounts-payable/vendors/{$vendor['id']}/submit")->assertOk();
        $requestId = ApprovalRequest::query()->where('approvable_id', $vendor['id'])->value('id');
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();

        // Back as maker — editing an active vendor is blocked.
        Sanctum::actingAs($maker->fresh());
        $this->putJson("/api/v1/accounts-payable/vendors/{$vendor['id']}", [
            'name' => 'Renamed', 'vendor_type' => 'supplier',
        ])->assertStatus(409);
    }
}
