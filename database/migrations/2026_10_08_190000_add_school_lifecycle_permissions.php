<?php

use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = [];

        foreach (['school.lifecycle.read', 'school.lifecycle.manage'] as $key) {
            $permission = AuthorizationCatalogue::permissions()[$key];
            $permissionIds[$key] = DB::table('permissions')->where('key', $key)->value('id')
                ?? DB::table('permissions')->insertGetId([
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

        foreach (['school_admin', 'proprietor'] as $roleKey) {
            $roleId = DB::table('roles')->where('key', $roleKey)->value('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE schools ADD CONSTRAINT schools_status_allowed CHECK (status IN ('active', 'suspended', 'archived'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE schools DROP CONSTRAINT IF EXISTS schools_status_allowed');
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['school.lifecycle.read', 'school.lifecycle.manage'])
            ->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
