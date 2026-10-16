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
            'platform.subscription-plans.read' => ['label' => 'Read subscription plans', 'description' => 'View safe platform subscription plan versions and entitlement keys.'],
            'platform.subscription-plans.manage' => ['label' => 'Manage subscription plans', 'description' => 'Create and lifecycle-manage configuration-only platform subscription plans.'],
            'platform.entitlements.read' => ['label' => 'Read school entitlements', 'description' => 'View effective entitlement keys for the trusted school.'],
            'platform.entitlements.manage' => ['label' => 'Manage school entitlements', 'description' => 'Assign an active platform plan to the trusted school.'],
        ];

        foreach ($catalogue as $key => $attributes) {
            Permission::query()->updateOrCreate(['key' => $key], [...$attributes, 'is_active' => true]);
        }

        $permissions = Permission::query()->whereIn('key', array_keys($catalogue))->pluck('id');
        Role::query()->whereIn('key', ['school_admin', 'proprietor'])->each(function (Role $role) use ($permissions): void {
            $role->permissions()->syncWithoutDetaching($permissions->all());
        });
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', [
            'platform.subscription-plans.read', 'platform.subscription-plans.manage',
            'platform.entitlements.read', 'platform.entitlements.manage',
        ])->get();
        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
