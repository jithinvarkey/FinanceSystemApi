<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalService;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0.4 — Approval engine: threshold routing + maker-checker SoD.
 */
final class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->service = app(ApprovalService::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user->fresh();
    }

    private function document(): User
    {
        return User::factory()->create();
    }

    public function test_single_step_workflow_routes_then_approves(): void
    {
        $maker = $this->userWithRole('accountant');
        $controller = $this->userWithRole('finance-controller');

        $request = $this->service->initiate($this->document(), 'journal_entry', 1000, $maker->id);
        $this->assertSame(ApprovalStatus::Pending, $request->status);
        $this->assertSame(1, $request->current_sequence);

        $request = $this->service->approve($request, $controller);
        $this->assertSame(ApprovalStatus::Approved, $request->status);
        $this->assertNull($request->current_sequence);
    }

    public function test_threshold_engages_second_step_for_large_amount(): void
    {
        $maker = $this->userWithRole('accountant');
        $controller = $this->userWithRole('finance-controller');
        $manager = $this->userWithRole('finance-manager');

        $request = $this->service->initiate($this->document(), 'payment', 100000, $maker->id);

        // Controller clears step 1; still pending at step 2 (manager).
        $request = $this->service->approve($request, $controller);
        $this->assertSame(ApprovalStatus::Pending, $request->status);
        $this->assertSame(2, $request->current_sequence);

        // Manager clears step 2 -> approved.
        $request = $this->service->approve($request, $manager);
        $this->assertSame(ApprovalStatus::Approved, $request->status);
    }

    public function test_below_threshold_skips_second_step(): void
    {
        $maker = $this->userWithRole('accountant');
        $controller = $this->userWithRole('finance-controller');

        $request = $this->service->initiate($this->document(), 'payment', 1000, $maker->id);
        $this->assertSame(1, $request->current_sequence);

        $request = $this->service->approve($request, $controller);
        $this->assertSame(ApprovalStatus::Approved, $request->status);
    }

    public function test_raiser_cannot_approve_own_request(): void
    {
        $controller = $this->userWithRole('finance-controller');
        $request = $this->service->initiate($this->document(), 'journal_entry', 1000, $controller->id);

        $this->expectException(FinanceRuleException::class);
        $this->service->approve($request, $controller);
    }

    public function test_same_approver_cannot_clear_two_steps(): void
    {
        $maker = $this->userWithRole('accountant');
        $manager = $this->userWithRole('finance-manager'); // holds both approve and post

        $request = $this->service->initiate($this->document(), 'payment', 100000, $maker->id);
        $request = $this->service->approve($request, $manager); // clears step 1

        $this->expectException(FinanceRuleException::class);
        $this->service->approve($request, $manager); // step 2 blocked: already acted
    }

    public function test_actor_without_step_permission_is_blocked(): void
    {
        $maker = $this->userWithRole('accountant');
        $auditor = $this->userWithRole('auditor'); // no general-ledger.approve

        $request = $this->service->initiate($this->document(), 'journal_entry', 1000, $maker->id);

        $this->expectException(FinanceRuleException::class);
        $this->service->approve($request, $auditor);
    }

    public function test_reject_is_terminal_and_records_reason(): void
    {
        $maker = $this->userWithRole('accountant');
        $controller = $this->userWithRole('finance-controller');

        $request = $this->service->initiate($this->document(), 'journal_entry', 1000, $maker->id);
        $request = $this->service->reject($request, $controller, 'Unsupported entry');

        $this->assertSame(ApprovalStatus::Rejected, $request->status);
        $this->assertSame('Unsupported entry', $request->actions()->first()->comment);
    }

    public function test_unknown_document_type_throws(): void
    {
        $maker = $this->userWithRole('accountant');

        $this->expectException(FinanceRuleException::class);
        $this->service->initiate($this->document(), 'no_such_type', 100, $maker->id);
    }
}
