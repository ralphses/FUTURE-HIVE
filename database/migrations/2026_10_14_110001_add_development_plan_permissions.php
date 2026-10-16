<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([
            'counselling.development-plans.read' => 'Read development plans',
            'counselling.development-plans.manage' => 'Manage development plans',
            'counselling.development-plans.share' => 'Share development plans',
        ] as $key => $label) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['label' => $label, 'description' => $label.' within authorized counselling scope.', 'created_at' => $now, 'updated_at' => $now]);
        }

        $roles = DB::table('roles')->whereIn('key', ['counsellor', 'school_admin', 'principal'])->pluck('id', 'key');
        $permissions = DB::table('permissions')->whereIn('key', array_keys([
            'counselling.development-plans.read' => true,
            'counselling.development-plans.manage' => true,
            'counselling.development-plans.share' => true,
        ]))->pluck('id', 'key');
        foreach ($roles as $role => $roleId) {
            foreach ($permissions as $permission => $permissionId) {
                if ($role !== 'counsellor' && $permission === 'counselling.development-plans.share') {
                    continue;
                }
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void {}
};
