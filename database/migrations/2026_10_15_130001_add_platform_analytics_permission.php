<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'platform.analytics.read'],
            ['public_id' => (string) Str::uuid7(), 'label' => 'Read platform operational analytics', 'description' => 'Read aggregate platform operational analytics without sensitive source data.', 'scope' => 'platform', 'is_active' => true],
        );

        $roleIds = Role::query()->whereIn('key', ['platform_admin', 'platform_ops'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permission->getKey()], ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $permissionId = Permission::query()->where('key', 'platform.analytics.read')->value('id');
        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            Permission::query()->whereKey($permissionId)->delete();
        }
    }
};
