<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LineOfBusiness;
use App\Models\Policy;
use App\Models\Product;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P4.17 Slice E (§11) — Policy renewal: clone an expiring policy into a new
 * linked draft for the next term.
 */
final class PolicyRenewalTest extends TestCase
{
    use RefreshDatabase;

    private int $policyId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class]);

        $u = User::factory()->create();
        $vat15 = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'is_recoverable' => true, 'status' => 'active']);
        $customer = Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'payment_terms_days' => 30, 'created_by' => $u->id]);
        $insurer = Vendor::query()->create(['vendor_code' => 'INS-1', 'name' => 'Tawuniya', 'vendor_type' => 'insurer', 'status' => 'active', 'currency_code' => 'SAR', 'created_by' => $u->id]);
        $lob = LineOfBusiness::query()->create(['code' => 'GEN', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        $product = Product::query()->create(['lob_id' => $lob->id, 'code' => 'PROP', 'name' => 'Property', 'default_commission_rate' => 15.0, 'default_tax_code_id' => $vat15->id, 'status' => 'active']);
        $this->productId = $product->id;

        // An issued policy ending in 20 days.
        $this->policyId = Policy::query()->create([
            'policy_number' => 'POL-1', 'customer_id' => $customer->id, 'insurer_id' => $insurer->id, 'product_id' => $product->id,
            'start_date' => now()->subYear()->addDays(20)->toDateString(), 'end_date' => now()->addDays(20)->toDateString(),
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'payment_method' => 'full', 'tax_code_id' => $vat15->id,
            'net_premium' => 10000, 'premium_tax_amount' => 1500, 'gross_premium' => 11500,
            'commission_rate' => 15, 'commission_amount' => 1500, 'commission_tax_amount' => 225,
            'status' => 'issued', 'created_by' => $u->id,
        ])->id;
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_expiring_lists_the_policy(): void
    {
        $this->actingAsRole('accountant');

        $this->getJson('/api/v1/policies/expiring?days=30')
            ->assertOk()
            ->assertJsonPath('data.0.policy_number', 'POL-1');
    }

    public function test_renew_creates_a_linked_draft_for_the_next_term(): void
    {
        $this->actingAsRole('accountant');

        $data = $this->postJson("/api/v1/policies/{$this->policyId}/renew", [
            'net_premium' => 12000, 'commission_rate' => 10,
        ])->assertCreated()->json('data');

        $this->assertSame('draft', $data['status']);
        $this->assertSame($this->policyId, $data['renewed_from_policy_id']);
        $this->assertSame('POL-1', $data['renewed_from_number']);
        // Renewal premium + the lower 10% commission rate applied.
        $this->assertEqualsWithDelta(13800, (float) $data['gross_premium'], 0.01); // 12,000 + 15% VAT
        $this->assertEqualsWithDelta(1200, (float) $data['commission_amount'], 0.01); // 12,000 × 10%

        // Next term starts the day after the old policy ends.
        $old = Policy::query()->find($this->policyId);
        $this->assertSame($old->end_date->copy()->addDay()->toDateString(), $data['start_date']);
    }

    public function test_cannot_renew_a_policy_twice(): void
    {
        $this->actingAsRole('accountant');
        $this->postJson("/api/v1/policies/{$this->policyId}/renew")->assertCreated();

        $this->postJson("/api/v1/policies/{$this->policyId}/renew")->assertStatus(422);
    }

    public function test_cannot_renew_a_draft_policy(): void
    {
        Policy::query()->whereKey($this->policyId)->update(['status' => 'draft']);
        $this->actingAsRole('accountant');

        $this->postJson("/api/v1/policies/{$this->policyId}/renew")->assertStatus(422);
    }
}
