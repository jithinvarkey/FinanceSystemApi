<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * P-IMP — Admin bulk import framework: per-entity Excel upload, dry-run preview,
 * atomic commit.
 */
final class ImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        ChartOfAccount::query()->create(['code' => '120501', 'name' => 'AR control', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    /** @param list<list<string>> $rows first row = headers */
    private function xlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                $sheet->setCellValue([$c + 1, $r + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_lists_importers(): void
    {
        $this->actingAsRole('finance-manager');

        $this->getJson('/api/v1/admin/imports')
            ->assertOk()
            ->assertJsonFragment(['key' => 'customers'])
            ->assertJsonFragment(['key' => 'opening-receivables']);
    }

    public function test_template_downloads(): void
    {
        $this->actingAsRole('finance-manager');

        $this->get('/api/v1/admin/imports/customers/template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_preview_flags_bad_rows_without_writing(): void
    {
        $this->actingAsRole('finance-manager');

        $file = $this->xlsx([
            ['customer_code', 'name', 'customer_type'],
            ['CUST-1', 'Good Co', 'corporate'],
            ['CUST-2', '', 'corporate'],          // missing name
            ['CUST-3', 'Bad Type', 'martian'],     // invalid type
        ]);

        $this->postJson('/api/v1/admin/imports/customers/preview', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.valid', 1)
            ->assertJsonPath('data.invalid', 2);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_commit_creates_rows(): void
    {
        $this->actingAsRole('finance-manager');

        $file = $this->xlsx([
            ['customer_code', 'name', 'customer_type', 'receivable_account_code'],
            ['CUST-1', 'Gulf Logistics', 'corporate', '120501'],
            ['CUST-2', 'Najd Trading', 'individual', ''],
        ]);

        $this->postJson('/api/v1/admin/imports/customers/commit', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.committed', 2);

        $this->assertSame(2, Customer::query()->count());
        $this->assertNotNull(Customer::query()->where('customer_code', 'CUST-1')->first()->default_receivable_account_id);
    }

    public function test_commit_is_atomic_on_a_bad_row(): void
    {
        $this->actingAsRole('finance-manager');

        $file = $this->xlsx([
            ['customer_code', 'name', 'customer_type'],
            ['CUST-1', 'Good Co', 'corporate'],
            ['CUST-2', '', 'corporate'],   // bad → whole file rolls back
        ]);

        $this->postJson('/api/v1/admin/imports/customers/commit', ['file' => $file])
            ->assertStatus(422);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_commit_requires_post_permission(): void
    {
        $this->actingAsRole('accountant'); // has general-ledger.manage, not .post

        $file = $this->xlsx([['customer_code', 'name', 'customer_type'], ['CUST-1', 'Co', 'corporate']]);

        $this->postJson('/api/v1/admin/imports/customers/commit', ['file' => $file])->assertStatus(403);
    }

    public function test_historical_chain_policy_endorsement_collection_no_gl(): void
    {
        $this->actingAsRole('finance-manager');
        [$bank] = $this->policyFixture();

        // 1) historical policy
        $this->postJson('/api/v1/admin/imports/historical-policies/commit', ['file' => $this->xlsx([
            ['policy_number', 'customer_code', 'insurer_code', 'product_code', 'start_date', 'end_date', 'net_premium'],
            ['HPOL-1', 'CUST-1', 'INS-1', 'PROP', '2018-01-01', '2018-12-31', '10000'],
        ])])->assertOk()->assertJsonPath('data.committed', 1);

        $policy = \App\Models\Policy::query()->where('policy_number', 'HPOL-1')->first();
        $this->assertSame('issued', $policy->status->value);
        $this->assertSame('import', $policy->source_system);

        // 2) historical endorsement on it
        $this->postJson('/api/v1/admin/imports/historical-endorsements/commit', ['file' => $this->xlsx([
            ['policy_number', 'type', 'effective_date', 'delta_net_premium'],
            ['HPOL-1', 'addition', '2018-06-01', '2000'],
        ])])->assertOk()->assertJsonPath('data.committed', 1);

        $this->assertSame(1, \App\Models\PolicyEndorsement::query()->where('policy_id', $policy->id)->where('status', 'posted')->count());

        // 3) historical premium collection
        $this->postJson('/api/v1/admin/imports/historical-collections/commit', ['file' => $this->xlsx([
            ['policy_number', 'collection_date', 'amount', 'bank_account_code'],
            ['HPOL-1', '2018-03-01', '11500', $bank],
        ])])->assertOk()->assertJsonPath('data.committed', 1);

        $this->assertSame(1, \App\Models\PremiumCollection::query()->where('policy_id', $policy->id)->where('status', 'posted')->count());

        // None of the historical imports re-post the GL.
        $this->assertSame(0, \App\Models\GlTransaction::query()->count());
    }

    /** @return array{0: string} the bank account code */
    private function policyFixture(): array
    {
        $ar = \App\Models\ChartOfAccount::query()->where('code', '120501')->first();
        $ap = \App\Models\ChartOfAccount::query()->create(['code' => '220101', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $bank = \App\Models\ChartOfAccount::query()->create(['code' => '110101', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active']);
        $comm = \App\Models\ChartOfAccount::query()->create(['code' => '4100', 'name' => 'Commission', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $vat = \App\Models\TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'is_recoverable' => true, 'status' => 'active']);

        $u = \App\Models\User::factory()->create();
        \App\Models\Customer::query()->create(['customer_code' => 'CUST-1', 'name' => 'Gulf', 'customer_type' => 'corporate', 'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $ar->id, 'payment_terms_days' => 30, 'created_by' => $u->id]);
        \App\Models\Vendor::query()->create(['vendor_code' => 'INS-1', 'name' => 'Tawuniya', 'vendor_type' => 'insurer', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $ap->id, 'created_by' => $u->id]);
        $lob = \App\Models\LineOfBusiness::query()->create(['code' => 'GEN', 'name' => 'General', 'is_life' => false, 'status' => 'active']);
        \App\Models\Product::query()->create(['lob_id' => $lob->id, 'code' => 'PROP', 'name' => 'Property', 'default_commission_rate' => 15.0, 'default_tax_code_id' => $vat->id, 'commission_revenue_account_id' => $comm->id, 'status' => 'active']);

        return ['110101'];
    }
}
