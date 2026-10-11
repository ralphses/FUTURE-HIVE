<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['imports.historical-academic.read', 'imports.historical-academic.manage'] as $key) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['label' => $key === 'imports.historical-academic.read' ? 'Read historical academic imports' : 'Manage historical academic imports', 'description' => 'Access unverified historical academic import archives.', 'created_at' => $now, 'updated_at' => $now]);
        }
        $roles = DB::table('roles')->whereIn('key', ['school_admin', 'principal', 'proprietor'])->pluck('id');
        $permissions = DB::table('permissions')->whereIn('key', ['imports.historical-academic.read', 'imports.historical-academic.manage'])->pluck('id');
        foreach ($roles as $roleId) {
            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', ['imports.historical-academic.read', 'imports.historical-academic.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
