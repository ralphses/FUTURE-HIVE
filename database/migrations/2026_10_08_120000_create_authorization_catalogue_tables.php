<?php

use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('key', 80)->unique();
            $table->string('label', 120);
            $table->text('description');
            $table->string('scope', 32)->default('school');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('key', 120)->unique();
            $table->string('label', 120);
            $table->text('description');
            $table->string('scope', 32)->default('school');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('membership_roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_membership_id')->constrained('school_memberships')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->unsignedBigInteger('assigned_by')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();
            $table->index(['school_membership_id', 'revoked_at']);
        });

        DB::statement('CREATE UNIQUE INDEX membership_roles_active_unique ON membership_roles (school_membership_id, role_id) WHERE revoked_at IS NULL');

        $roleIds = [];
        foreach (AuthorizationCatalogue::roles() as $key => $role) {
            $roleIds[$key] = DB::table('roles')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'key' => $key,
                'label' => $role['label'],
                'description' => $role['description'],
                'scope' => 'school',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = [];
        foreach (AuthorizationCatalogue::permissions() as $key => $permission) {
            $permissionIds[$key] = DB::table('permissions')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'key' => $key,
                'label' => $permission['label'],
                'description' => $permission['description'],
                'scope' => 'school',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (AuthorizationCatalogue::rolePermissions() as $roleKey => $permissionKeys) {
            foreach ($permissionKeys as $permissionKey) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleIds[$roleKey],
                    'permission_id' => $permissionIds[$permissionKey],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $schoolAdminRoleId = $roleIds['school_admin'];
        DB::table('school_memberships')
            ->where('is_owner', true)
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'user_id'])
            ->each(function (object $membership) use ($schoolAdminRoleId): void {
                DB::table('membership_roles')->insert([
                    'public_id' => (string) Str::uuid7(),
                    'school_membership_id' => $membership->id,
                    'role_id' => $schoolAdminRoleId,
                    'assigned_by' => $membership->user_id,
                    'assigned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS membership_roles_active_unique');
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
