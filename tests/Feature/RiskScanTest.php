<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E3 — Risk & anomaly scan.
 */
final class RiskScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_flags_duplicate_payments(): void
    {
        $this->seed(RbacSeeder::class);
        $u = User::factory()->create();
        $bank = ChartOfAccount::query()->create(['code' => '1010', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $ap = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $vendor = Vendor::query()->create(['vendor_code' => 'V1', 'name' => 'Acme', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $ap, 'payment_terms_days' => 30, 'created_by' => $u->id]);

        // Two posted payments to the same vendor, same amount, 2 days apart.
        foreach ([['PMT-1', '2026-03-01'], ['PMT-2', '2026-03-03']] as [$no, $date]) {
            DB::table('vendor_payments')->insert([
                'payment_number' => $no, 'vendor_id' => $vendor->id, 'payment_date' => $date, 'bank_account_id' => $bank,
                'payment_method' => 'bank_transfer', 'currency_code' => 'SAR', 'exchange_rate' => 1, 'amount' => 5000,
                'status' => 'posted', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $u2 = User::factory()->create();
        $u2->roles()->attach(Role::query()->where('name', 'auditor')->firstOrFail());
        Sanctum::actingAs($u2->fresh());

        $data = $this->getJson('/api/v1/compliance/risk-scan')->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['duplicate_payment'] ?? 0);
        $this->assertSame('duplicate_payment', $data['findings'][0]['category']);
        $this->assertSame('high', $data['findings'][0]['severity']);
    }
}
