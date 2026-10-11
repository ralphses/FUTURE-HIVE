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
            'imports.validation.read' => ['label' => 'Read import validation', 'description' => 'View row validation results and normalized previews.'],
            'imports.validation.manage' => ['label' => 'Validate imports', 'description' => 'Run validation and normalization over a mapped staged import.'],
        ];

        foreach ($permissions as $key => $attributes) {
            Permission::query()->updateOrCreate(['key' => $key], $attributes);
        }

        foreach (['school_admin', 'principal', 'proprietor'] as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            if ($role === null) {
                continue;
            }

            foreach (array_keys($permissions) as $permissionKey) {
                $permissionId = Permission::query()->where('key', $permissionKey)->value('id');
                DB::table('role_permissions')->updateOrInsert(['role_id' => $role->getKey(), 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = Permission::query()->whereIn('key', ['imports.validation.read', 'imports.validation.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        Permission::query()->whereIn('id', $permissionIds)->delete();
    }
};
