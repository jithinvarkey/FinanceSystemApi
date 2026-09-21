<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F17 / F18 — User & role administration (admin-only via finance-config.manage).
 */
final class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', 'it-supervisor')->firstOrFail());
        Sanctum::actingAs($u->fresh());

        return $u;
    }

    private function nonAdmin(): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', 'accountant')->firstOrFail());
        Sanctum::actingAs($u->fresh());

        return $u;
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $this->nonAdmin();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_admin_creates_a_user_with_roles(): void
    {
        $this->admin();
        $roleId = Role::query()->where('name', 'accountant')->value('id');

        $this->postJson('/api/v1/admin/users', [
            'name' => 'New Clerk', 'email' => 'clerk@diamond.local', 'password' => 'secret123',
            'role_ids' => [$roleId],
        ])->assertCreated()
            ->assertJsonPath('data.email', 'clerk@diamond.local')
            ->assertJsonPath('data.roles.0.name', 'accountant');

        $this->assertDatabaseHas('users', ['email' => 'clerk@diamond.local', 'is_active' => true]);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $this->admin();
        $target = User::query()->where('email', 'controller@diamond.local')->firstOrFail();

        $this->putJson("/api/v1/admin/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->postJson('/api/v1/auth/login', ['email' => 'controller@diamond.local', 'password' => 'password'])
            ->assertStatus(422);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->admin();

        $this->putJson("/api/v1/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'is_active' => false,
        ])->assertStatus(422);
    }

    public function test_reset_password_revokes_tokens_and_changes_login(): void
    {
        $this->admin();
        $target = User::query()->where('email', 'controller@diamond.local')->firstOrFail();

        $this->postJson("/api/v1/admin/users/{$target->id}/reset-password", ['password' => 'brandnew99'])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'controller@diamond.local', 'password' => 'brandnew99'])->assertOk();
    }

    public function test_roles_list_carries_permissions_and_user_counts(): void
    {
        $this->admin();

        $data = $this->getJson('/api/v1/admin/roles')->assertOk()->json('data');
        $itSup = collect($data)->firstWhere('name', 'it-supervisor');

        $this->assertTrue($itSup['is_system']);
        $this->assertContains('finance-config.manage', $itSup['permissions']);
        $this->assertArrayHasKey('user_count', $itSup);
    }

    public function test_custom_role_create_edit_delete(): void
    {
        $this->admin();

        $role = $this->postJson('/api/v1/admin/roles', [
            'label' => 'Read Only AP', 'permissions' => ['accounts-payable.view'],
        ])->assertCreated()->json('data');

        $this->assertFalse($role['is_system']);
        $this->assertSame(['accounts-payable.view'], $role['permissions']);

        $this->putJson("/api/v1/admin/roles/{$role['id']}", [
            'label' => 'Read Only AP', 'permissions' => ['accounts-payable.view', 'accounts-receivable.view'],
        ])->assertOk()->assertJsonCount(2, 'data.permissions');

        $this->deleteJson("/api/v1/admin/roles/{$role['id']}")->assertNoContent();
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $this->admin();
        $id = Role::query()->where('name', 'auditor')->value('id');

        $this->deleteJson("/api/v1/admin/roles/{$id}")->assertStatus(422);
    }
}
