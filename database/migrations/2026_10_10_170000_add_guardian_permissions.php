<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['key' => 'guardians.read', 'label' => 'Read guardians', 'description' => 'View approved guardian profile information in the selected school.'],
            ['key' => 'guardians.manage', 'label' => 'Manage guardians', 'description' => 'Create and update approved guardian profile information.'],
            ['key' => 'guardian.links.manage', 'label' => 'Manage guardian links', 'description' => 'Create, update and revoke school-scoped guardian relationships.'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['key' => $permission['key']], $permission);
        }

        $readRoles = ['school_admin', 'principal', 'proprietor', 'counsellor'];
        $manageRoles = ['school_admin', 'proprietor'];

        foreach ($readRoles as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            if (! $role instanceof Role) {
                continue;
            }
            foreach (['guardians.read', 'guardian.links.read'] as $permissionKey) {
                $permissionId = Permission::query()->where('key', $permissionKey)->value('id');
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
            }
        }

        foreach ($manageRoles as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            if (! $role instanceof Role) {
                continue;
            }
            foreach (['guardians.manage', 'guardian.links.manage'] as $permissionKey) {
                $permissionId = Permission::query()->where('key', $permissionKey)->value('id');
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $keys = ['guardians.read', 'guardians.manage', 'guardian.links.manage'];
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('id', $ids)->delete();
    }
};
