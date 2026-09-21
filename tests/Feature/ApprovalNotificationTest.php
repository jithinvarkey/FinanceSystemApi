<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\ApprovalDecidedNotification;
use App\Notifications\ApprovalRequestedNotification;
use App\Services\ApprovalService;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F19 — approval-lifecycle notifications: approvers are alerted when a document
 * needs them; the raiser hears the outcome.
 */
final class ApprovalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
    }

    private function userWithRole(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $u->fresh();
    }

    private function vendor(int $createdBy): Vendor
    {
        return Vendor::query()->create([
            'vendor_code' => 'VEND-2026-000001', 'name' => 'Acme', 'vendor_type' => 'supplier',
            'status' => 'active', 'currency_code' => 'SAR', 'payment_terms_days' => 30, 'created_by' => $createdBy,
        ]);
    }

    public function test_requested_notifies_approvers_but_not_the_raiser(): void
    {
        Notification::fake();
        $maker = $this->userWithRole('accountant');          // raises, cannot approve
        $approver = $this->userWithRole('finance-controller'); // holds accounts-payable.approve

        app(ApprovalService::class)->initiate($this->vendor($maker->id), 'vendor_invoice', 1000, $maker->id);

        Notification::assertSentTo($approver, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($maker, ApprovalRequestedNotification::class);
    }

    public function test_decision_notifies_the_raiser(): void
    {
        Notification::fake();
        $maker = $this->userWithRole('accountant');
        $approver = $this->userWithRole('finance-controller');

        $service = app(ApprovalService::class);
        $request = $service->initiate($this->vendor($maker->id), 'vendor_invoice', 1000, $maker->id);
        $service->approve($request, $approver);

        Notification::assertSentTo($maker, ApprovalDecidedNotification::class);
    }

    public function test_inbox_lists_unread_notifications(): void
    {
        Mail::fake(); // let the mail channel no-op; the database channel still writes
        $maker = $this->userWithRole('accountant');
        $approver = $this->userWithRole('finance-controller');

        app(ApprovalService::class)->initiate($this->vendor($maker->id), 'vendor_invoice', 1000, $maker->id);

        Sanctum::actingAs($approver);
        $res = $this->getJson('/api/v1/notifications')->assertOk();

        $res->assertJsonPath('unread', 1);
        $this->assertSame('approval_requested', $res->json('data.0.kind'));

        // Mark it read → unread drops to zero.
        $id = $res->json('data.0.id');
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('unread', 0);
    }
}
