<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $permissions = [
        'imports.mapping.read' => ['Read import mappings', 'View saved column mappings and safe import previews.'],
        'imports.mapping.manage' => ['Manage import mappings', 'Create a new immutable column mapping for a staged import.'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $key => [$label, $description]) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'description' => $description, 'scope' => 'school', 'is_active' => true],
            );
        }

        foreach (['school_admin', 'proprietor', 'principal'] as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            if (! $role instanceof Role) {
                continue;
            }

            foreach (array_keys($this->permissions) as $permissionKey) {
                DB::table('role_permissions')->updateOrInsert([
                    'role_id' => $role->id,
                    'permission_id' => Permission::query()->where('key', $permissionKey)->value('id'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = Permission::query()->whereIn('key', array_keys($this->permissions))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        Permission::query()->whereIn('key', array_keys($this->permissions))->delete();
    }
};
