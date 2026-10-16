<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $catalogue = [
            'platform.support-cases.read' => ['label' => 'Read support cases', 'description' => 'View safe platform support-case metadata.'],
            'platform.support-cases.manage' => ['label' => 'Manage support cases', 'description' => 'Create and lifecycle-manage platform support cases.'],
            'platform.support-access.read' => ['label' => 'Read support access grants', 'description' => 'View safe, scoped support-access grant metadata.'],
            'platform.support-access.request' => ['label' => 'Request support access', 'description' => 'Request bounded, expiring support access for an approved case.'],
            'platform.support-access.approve' => ['label' => 'Approve support access', 'description' => 'Approve support access with maker-checker authorization.'],
            'platform.support-access.revoke' => ['label' => 'Revoke support access', 'description' => 'Revoke active support access grants.'],
        ];

        foreach ($catalogue as $key => $attributes) {
            Permission::query()->updateOrCreate(['key' => $key], [...$attributes, 'scope' => 'platform', 'is_active' => true]);
        }

        $permissionIds = Permission::query()->whereIn('key', array_keys($catalogue))->pluck('id');
        Role::query()->whereIn('key', ['platform_admin'])->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching($permissionIds->all()));
        Role::query()->whereIn('key', ['platform_ops'])->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', ['platform.support-cases.read', 'platform.support-cases.manage', 'platform.support-access.read', 'platform.support-access.request', 'platform.support-access.revoke'])->pluck('id')->all()));
        Role::query()->whereIn('key', ['platform_support'])->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', ['platform.support-cases.read', 'platform.support-access.read', 'platform.support-access.request'])->pluck('id')->all()));
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['platform.support-cases.read', 'platform.support-cases.manage', 'platform.support-access.read', 'platform.support-access.request', 'platform.support-access.approve', 'platform.support-access.revoke'])->get();
        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
