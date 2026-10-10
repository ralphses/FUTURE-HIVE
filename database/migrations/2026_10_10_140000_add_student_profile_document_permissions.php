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
            ['key' => 'students.profile.read', 'label' => 'Read student profiles', 'description' => 'View approved student profile information in the selected school.'],
            ['key' => 'students.profile.manage', 'label' => 'Manage student profiles', 'description' => 'Create and update student profile information in the selected school.'],
            ['key' => 'students.documents.read', 'label' => 'Read student documents', 'description' => 'View approved student document metadata and signed downloads.'],
            ['key' => 'students.documents.manage', 'label' => 'Manage student documents', 'description' => 'Upload and revoke student documents in the selected school.'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['key' => $permission['key']], $permission);
        }

        $readRoles = ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor', 'counsellor'];
        $manageRoles = ['school_admin', 'proprietor'];

        foreach ($readRoles as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            if (! $role instanceof Role) {
                continue;
            }
            foreach (['students.profile.read', 'students.documents.read'] as $permissionKey) {
                $permissionId = Permission::query()->where('key', $permissionKey)->value('id');
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
            }
        }

        foreach ($manageRoles as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            if (! $role instanceof Role) {
                continue;
            }
            foreach (['students.profile.manage', 'students.documents.manage'] as $permissionKey) {
                $permissionId = Permission::query()->where('key', $permissionKey)->value('id');
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = Permission::query()->whereIn('key', [
            'students.profile.read', 'students.profile.manage', 'students.documents.read', 'students.documents.manage',
        ])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        Permission::query()->whereIn('id', $permissionIds)->delete();
    }
};
