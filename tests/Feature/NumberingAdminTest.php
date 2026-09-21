<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\DocumentNumberService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F21 — Document numbering administration.
 */
final class NumberingAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function admin(): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', 'it-supervisor')->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_index_lists_schemes_with_previews(): void
    {
        $this->admin();

        $data = $this->getJson('/api/v1/finance-config/numbering')->assertOk()->json('data');
        $vinv = collect($data)->firstWhere('document_type', 'vendor_invoice');

        $this->assertSame('VINV', $vinv['prefix']);
        $this->assertTrue($vinv['yearly']);
        $this->assertStringStartsWith('VINV-', $vinv['next_preview']);
    }

    public function test_admin_can_set_the_next_number(): void
    {
        $this->admin();
        $year = (int) now()->year;

        $this->postJson('/api/v1/finance-config/numbering', [
            'document_type' => 'vendor_invoice', 'period_year' => $year,
            'next_number' => 500, 'prefix' => 'VINV', 'padding' => 6,
        ])->assertOk()->assertJsonPath('data.next_number', 500);

        // The numbering service now issues from 500.
        $issued = app(DocumentNumberService::class)->next('vendor_invoice', Carbon::create($year));
        $this->assertSame("VINV-{$year}-000500", $issued);
    }
}
