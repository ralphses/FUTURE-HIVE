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

        foreach (AuthorizationCatalogue::permissions() as $key => $permission) {
            $existing = DB::table('permissions')->where('key', $key)->first();
            $permissionIds[$key] = $existing->id ?? DB::table('permissions')->insertGetId([
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
            $roleId = DB::table('roles')->where('key', $roleKey)->value('id');

            foreach ($permissionKeys as $permissionKey) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionIds[$permissionKey],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $keys = [
            'academic.assignments.read',
            'academic.assignments.manage',
            'guardian.links.read',
            'student.self.read',
        ];
        $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
