<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0.1 — Role-based access control.
 *
 * A user has many roles; a role has many permissions. Authorization checks
 * resolve a user's effective permission set (the union across their roles),
 * replacing the development placeholder that granted every ability to all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50)->unique()->comment('Machine key, e.g. finance-controller');
            $table->string('label', 80)->comment('Human label, e.g. Finance Controller');
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false)->comment('System roles cannot be deleted');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique()->comment('Ability key, e.g. general-ledger.approve');
            $table->string('label', 120);
            $table->string('module', 40)->index()->comment('Grouping, e.g. general-ledger');
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
