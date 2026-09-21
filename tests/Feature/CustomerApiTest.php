<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P4.1–P4.5 — Customer master: draft -> approval -> active, blocking, bank details.
 */
final class CustomerApiTest extends TestCase
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

    private function createCustomer(array $overrides = []): array
    {
        return $this->postJson('/api/v1/accounts-receivable/customers', array_merge([
            'name' => 'Gulf Logistics LLC',
            'customer_type' => 'corporate',
            'trn' => '300123456700003',
            'payment_terms_days' => 30,
            'credit_limit' => 50000,
        ], $overrides))->json('data');
    }

    public function test_maker_creates_draft_customer_with_generated_code(): void
    {
        $this->actingAsRole('accountant');

        $customer = $this->createCustomer();

        $this->assertSame('draft', $customer['status']);
        $this->assertStringStartsWith('CUST-', $customer['customer_code']);
        $this->assertFalse($customer['is_transactable']);
    }

    public function test_invalid_trn_is_rejected(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-receivable/customers', [
            'name' => 'Bad TRN Co', 'customer_type' => 'corporate', 'trn' => '123',
        ])->assertStatus(422)->assertJsonValidationErrors('trn');
    }

    public function test_submit_raises_approval_and_marks_pending(): void
    {
        $this->actingAsRole('accountant');
        $customer = $this->createCustomer();

        $this->postJson("/api/v1/accounts-receivable/customers/{$customer['id']}/submit")
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $this->assertDatabaseHas('approval_requests', [
            'approvable_type' => Customer::class,
            'approvable_id' => $customer['id'],
            'document_type' => 'customer',
            'status' => 'pending',
        ]);
    }

    public function test_approval_activates_customer(): void
    {
        $this->actingAsRole('accountant');
        $customer = $this->createCustomer();
        $this->postJson("/api/v1/accounts-receivable/customers/{$customer['id']}/submit")->assertOk();

        $requestId = ApprovalRequest::query()
            ->where('approvable_type', Customer::class)
            ->where('approvable_id', $customer['id'])
            ->value('id');

        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fresh = Customer::query()->find($customer['id']);
        $this->assertSame('active', $fresh->status->value);
        $this->assertTrue($fresh->isTransactable());
    }

    public function test_block_requires_reason_and_stops_transactability(): void
    {
        $this->actingAsRole('accountant');
        $customer = $this->createCustomer();

        $this->postJson("/api/v1/accounts-receivable/customers/{$customer['id']}/block", [])
            ->assertStatus(422);

        $blocked = $this->postJson("/api/v1/accounts-receivable/customers/{$customer['id']}/block", [
            'reason' => 'Overdue beyond credit terms',
        ])->assertOk()->json('data');

        $this->assertTrue($blocked['is_blocked']);
        $this->assertFalse($blocked['is_transactable']);

        $unblocked = $this->postJson("/api/v1/accounts-receivable/customers/{$customer['id']}/unblock")
            ->assertOk()->json('data');
        $this->assertFalse($unblocked['is_blocked']);
    }

    public function test_bank_account_is_unverified_until_a_checker_verifies(): void
    {
        $this->actingAsRole('accountant');
        $customer = $this->createCustomer();

        $account = $this->postJson("/api/v1/accounts-receivable/customers/{$customer['id']}/bank-accounts", [
            'bank_name' => 'Al Rajhi Bank',
            'account_name' => 'Gulf Logistics LLC',
            'iban' => 'SA0380000000608010167519',
            'is_primary' => true,
        ])->assertCreated()->json('data');

        $this->assertFalse($account['is_verified']);

        // Maker cannot verify (no approve permission).
        $this->postJson("/api/v1/accounts-receivable/bank-accounts/{$account['id']}/verify")
            ->assertStatus(403);

        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/accounts-receivable/bank-accounts/{$account['id']}/verify")
            ->assertOk()->assertJsonPath('data.is_verified', true);
    }

    public function test_active_customer_is_not_editable(): void
    {
        $maker = $this->actingAsRole('accountant');
        $customer = $this->createCustomer();
        $this->postJson("/api/v1/accounts-receivable/customers/{$customer['id']}/submit")->assertOk();
        $requestId = ApprovalRequest::query()->where('approvable_id', $customer['id'])
            ->where('approvable_type', Customer::class)->value('id');
        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();

        Sanctum::actingAs($maker->fresh());
        $this->putJson("/api/v1/accounts-receivable/customers/{$customer['id']}", [
            'name' => 'Renamed', 'customer_type' => 'corporate',
        ])->assertStatus(409);
    }
}
