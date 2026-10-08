<?php

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (AuthorizationCatalogue::permissions() as $key => $definition) {
            if (! in_array($key, ['academic.subjects.read', 'academic.subjects.manage'], true)) {
                continue;
            }
            Permission::query()->firstOrCreate(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'is_active' => true, 'public_id' => (string) Str::uuid7()]);
        }

        foreach (AuthorizationCatalogue::rolePermissions() as $roleKey => $permissionKeys) {
            $role = Role::query()->where('key', $roleKey)->first();
            if ($role === null) {
                continue;
            }
            foreach ($permissionKeys as $permissionKey) {
                if (! in_array($permissionKey, ['academic.subjects.read', 'academic.subjects.manage'], true)) {
                    continue;
                }
                $permission = Permission::query()->where('key', $permissionKey)->first();
                if ($permission !== null) {
                    $role->permissions()->syncWithoutDetaching([$permission->getKey()]);
                }
            }
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['academic.subjects.read', 'academic.subjects.manage'])->pluck('id');
        if ($permissions->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissions)->delete();
            Permission::query()->whereIn('id', $permissions)->delete();
        }
    }
};
