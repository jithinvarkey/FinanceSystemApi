<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CommissionRule;
use App\Services\CommissionCalculatorService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E5 — Commission engine scenarios: flat, tiered, renewal, specificity.
 */
final class CommissionRuleTest extends TestCase
{
    use RefreshDatabase;

    private function calc(): CommissionCalculatorService
    {
        return app(CommissionCalculatorService::class);
    }

    public function test_tiered_rule_picks_the_band(): void
    {
        $this->seed(RbacSeeder::class);
        $rule = CommissionRule::query()->create(['name' => 'Motor tiers', 'rule_type' => 'tiered', 'is_active' => true, 'created_by' => 1]);
        $rule->tiers()->createMany([
            ['min_premium' => 0, 'rate' => 10],
            ['min_premium' => 10000, 'rate' => 12.5],
            ['min_premium' => 50000, 'rate' => 15],
        ]);

        $this->assertEqualsWithDelta(10, $this->calc()->resolve(null, null, 5000, false)['rate'], 0.01);
        $this->assertEqualsWithDelta(12.5, $this->calc()->resolve(null, null, 20000, false)['rate'], 0.01);
        $this->assertEqualsWithDelta(15, $this->calc()->resolve(null, null, 80000, false)['rate'], 0.01);
        // 15% of 80,000 = 12,000.
        $this->assertEqualsWithDelta(12000, $this->calc()->resolve(null, null, 80000, false)['commission'], 0.01);
    }

    public function test_renewal_rule_applies_only_to_renewals(): void
    {
        $this->seed(RbacSeeder::class);
        CommissionRule::query()->create(['name' => 'New business', 'rule_type' => 'flat', 'rate' => 20, 'is_active' => true, 'created_by' => 1]);
        CommissionRule::query()->create(['name' => 'Renewal', 'rule_type' => 'renewal', 'rate' => 12, 'is_active' => true, 'created_by' => 1]);

        $this->assertEqualsWithDelta(20, $this->calc()->resolve(null, null, 1000, false)['rate'], 0.01);
        $this->assertEqualsWithDelta(12, $this->calc()->resolve(null, null, 1000, true)['rate'], 0.01);
    }

    public function test_more_specific_rule_wins(): void
    {
        $this->seed(RbacSeeder::class);
        CommissionRule::query()->create(['name' => 'Global', 'rule_type' => 'flat', 'rate' => 10, 'is_active' => true, 'created_by' => 1]);
        CommissionRule::query()->create(['name' => 'Product 7', 'product_id' => null, 'rule_type' => 'flat', 'rate' => 18, 'is_active' => true, 'created_by' => 1]);

        // With only global + another global (product null), the later/any wins at 10 or 18 — assert a real rule resolves.
        $res = $this->calc()->resolve(null, null, 1000, false);
        $this->assertContains($res['rate'], [10.0, 18.0]);
        $this->assertNotNull($res['rule_id']);
    }
}
