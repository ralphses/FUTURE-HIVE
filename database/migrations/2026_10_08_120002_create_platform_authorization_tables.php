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
        Schema::create('platform_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->unsignedBigInteger('assigned_by')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('break_glass_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('granted_to_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->restrictOnDelete();
            $table->string('resource_scope', 160);
            $table->text('purpose');
            $table->json('approval_context');
            $table->uuid('request_id')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();
            $table->index(['granted_to_user_id', 'school_id', 'revoked_at', 'expires_at'], 'break_glass_grants_access_index');
        });

        $now = now();

        foreach (AuthorizationCatalogue::platformRoles() as $key => $role) {
            DB::table('roles')->insert([
                'public_id' => (string) Str::uuid7(), 'key' => $key, 'label' => $role['label'],
                'description' => $role['description'], 'scope' => 'platform', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (AuthorizationCatalogue::platformPermissions() as $key => $permission) {
            DB::table('permissions')->insert([
                'public_id' => (string) Str::uuid7(), 'key' => $key, 'label' => $permission['label'],
                'description' => $permission['description'], 'scope' => 'platform', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (AuthorizationCatalogue::platformRolePermissions() as $roleKey => $permissionKeys) {
            $roleId = DB::table('roles')->where('key', $roleKey)->value('id');

            foreach ($permissionKeys as $permissionKey) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => DB::table('permissions')->where('key', $permissionKey)->value('id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::statement('CREATE UNIQUE INDEX platform_role_assignments_active_unique ON platform_role_assignments (user_id, role_id) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS platform_role_assignments_active_unique');
        Schema::dropIfExists('break_glass_access_grants');
        Schema::dropIfExists('platform_role_assignments');
        DB::table('role_permissions')->whereIn('role_id', DB::table('roles')->where('scope', 'platform')->pluck('id'))->delete();
        DB::table('permissions')->where('scope', 'platform')->delete();
        DB::table('roles')->where('scope', 'platform')->delete();
    }
};
