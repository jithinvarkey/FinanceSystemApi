<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0.6 — Attachment upload / list / download / delete with access control.
 */
final class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('local');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user->fresh();
    }

    public function test_authenticated_user_uploads_and_it_is_stored(): void
    {
        Sanctum::actingAs($uploader = $this->userWithRole('accountant'));

        $response = $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'journal_entry',
            'attachable_id' => 5,
            'category' => 'invoice',
            'file' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.original_name', 'invoice.pdf')
            ->assertJsonPath('data.category', 'invoice')
            ->assertJsonPath('data.within_retention', true);

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => 'journal_entry',
            'attachable_id' => 5,
            'uploaded_by' => $uploader->id,
        ]);

        $path = \App\Models\Attachment::query()->first()->path;
        Storage::disk('local')->assertExists($path);
    }

    public function test_upload_requires_a_file(): void
    {
        Sanctum::actingAs($this->userWithRole('accountant'));

        $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'journal_entry',
            'attachable_id' => 5,
        ])->assertStatus(422);
    }

    public function test_index_lists_attachments_for_a_document(): void
    {
        Sanctum::actingAs($this->userWithRole('accountant'));

        $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'vendor_invoice',
            'attachable_id' => 9,
            'file' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf'),
        ])->assertCreated();

        $this->getJson('/api/v1/attachments?attachable_type=vendor_invoice&attachable_id=9')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_uploader_can_download(): void
    {
        Sanctum::actingAs($this->userWithRole('accountant'));

        $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'journal_entry',
            'attachable_id' => 1,
            'file' => UploadedFile::fake()->create('doc.pdf', 30, 'application/pdf'),
        ])->assertCreated();

        $id = \App\Models\Attachment::query()->first()->id;
        $this->get("/api/v1/attachments/{$id}/download")->assertOk();
    }

    public function test_non_owner_without_admin_cannot_delete(): void
    {
        Sanctum::actingAs($this->userWithRole('accountant'));
        $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'journal_entry',
            'attachable_id' => 1,
            'file' => UploadedFile::fake()->create('doc.pdf', 30, 'application/pdf'),
        ])->assertCreated();
        $id = \App\Models\Attachment::query()->first()->id;

        Sanctum::actingAs($this->userWithRole('auditor')); // not uploader, no manage perm
        $this->deleteJson("/api/v1/attachments/{$id}")->assertStatus(403);
        $this->assertDatabaseHas('attachments', ['id' => $id]);
    }

    public function test_admin_can_delete_and_file_is_purged(): void
    {
        Sanctum::actingAs($this->userWithRole('accountant'));
        $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'journal_entry',
            'attachable_id' => 1,
            'file' => UploadedFile::fake()->create('doc.pdf', 30, 'application/pdf'),
        ])->assertCreated();
        $attachment = \App\Models\Attachment::query()->first();

        Sanctum::actingAs($this->userWithRole('it-supervisor')); // has finance-config.manage
        $this->deleteJson("/api/v1/attachments/{$attachment->id}")->assertNoContent();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);
    }
}
