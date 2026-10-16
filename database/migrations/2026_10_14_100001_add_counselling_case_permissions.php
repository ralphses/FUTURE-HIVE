<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            ['key' => 'counselling.cases.read', 'label' => 'Read counselling cases', 'description' => 'Read authorized counselling case metadata within the selected school.'],
            ['key' => 'counselling.cases.manage', 'label' => 'Manage counselling cases', 'description' => 'Create and lifecycle-manage authorized counselling cases.'],
            ['key' => 'counselling.notes.read', 'label' => 'Read counselling notes', 'description' => 'Read private notes only within an active counselling assignment.'],
            ['key' => 'counselling.notes.manage', 'label' => 'Manage counselling notes', 'description' => 'Create immutable private counselling notes within an active assignment.'],
        ];
        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(['key' => $permission['key']], $permission + ['created_at' => $now, 'updated_at' => $now]);
        }
        foreach (['school_admin', 'principal'] as $roleKey) {
            foreach (['counselling.cases.read', 'counselling.cases.manage'] as $permissionKey) {
                $this->grant($roleKey, $permissionKey);
            }
        }
        foreach (['counselling.cases.read', 'counselling.cases.manage', 'counselling.notes.read', 'counselling.notes.manage'] as $permissionKey) {
            $this->grant('counsellor', $permissionKey);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('key', ['counselling.cases.read', 'counselling.cases.manage', 'counselling.notes.read', 'counselling.notes.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    private function grant(string $roleKey, string $permissionKey): void
    {
        $roleId = DB::table('roles')->where('key', $roleKey)->value('id');
        $permissionId = DB::table('permissions')->where('key', $permissionKey)->value('id');
        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => now(), 'updated_at' => now()]);
        }
    }
};
