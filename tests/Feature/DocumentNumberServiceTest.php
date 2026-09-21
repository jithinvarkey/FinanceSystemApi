<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P0.5 — Document numbering: gap-free, formatted, year/branch scoped.
 */
final class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentNumberService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DocumentNumberService::class);
    }

    public function test_issues_formatted_sequential_numbers(): void
    {
        $date = Carbon::parse('2026-03-01');

        $this->assertSame('JV-2026-000001', $this->service->next('journal_entry', $date));
        $this->assertSame('JV-2026-000002', $this->service->next('journal_entry', $date));
        $this->assertSame('JV-2026-000003', $this->service->next('journal_entry', $date));
    }

    public function test_resets_per_year_for_yearly_types(): void
    {
        $this->service->next('journal_entry', Carbon::parse('2026-12-31'));
        $this->service->next('journal_entry', Carbon::parse('2026-12-31'));

        $this->assertSame('JV-2027-000001', $this->service->next('journal_entry', Carbon::parse('2027-01-01')));
    }

    public function test_non_yearly_type_has_no_year_segment(): void
    {
        $this->assertSame('FA-00001', $this->service->next('asset'));
        $this->assertSame('FA-00002', $this->service->next('asset'));
    }

    public function test_branches_have_independent_sequences(): void
    {
        $date = Carbon::parse('2026-03-01');

        $this->assertSame('PMT-2026-000001', $this->service->next('vendor_payment', $date, branchId: 1));
        $this->assertSame('PMT-2026-000001', $this->service->next('vendor_payment', $date, branchId: 2));
        $this->assertSame('PMT-2026-000002', $this->service->next('vendor_payment', $date, branchId: 1));
    }

    public function test_unknown_type_uses_default_prefix(): void
    {
        $this->assertSame('DOC-2026-000001', $this->service->next('something_new', Carbon::parse('2026-06-01')));
    }

    public function test_peek_does_not_consume(): void
    {
        $this->assertSame('RCP-2026-000001', $this->service->peek('receipt', Carbon::parse('2026-06-01')));
        $this->assertSame('RCP-2026-000001', $this->service->next('receipt', Carbon::parse('2026-06-01')));
        $this->assertSame('RCP-2026-000002', $this->service->peek('receipt', Carbon::parse('2026-06-01')));
    }

    public function test_hundred_calls_are_gap_free(): void
    {
        $date = Carbon::parse('2026-03-01');
        $numbers = [];
        for ($i = 0; $i < 100; $i++) {
            $numbers[] = $this->service->next('expense_claim', $date);
        }

        $this->assertCount(100, array_unique($numbers));
        $this->assertSame('EXP-2026-000001', $numbers[0]);
        $this->assertSame('EXP-2026-000100', $numbers[99]);
    }
}
