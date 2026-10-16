<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $read = Permission::firstOrCreate(['key' => 'platform.feature-flags.read'], ['public_id' => (string) Str::uuid7(), 'label' => 'Read platform feature flags', 'description' => 'Read safe platform feature-flag projections.']);
        $manage = Permission::firstOrCreate(['key' => 'platform.feature-flags.manage'], ['public_id' => (string) Str::uuid7(), 'label' => 'Manage platform feature flags', 'description' => 'Manage versioned platform feature-flag configuration.']);
        foreach (['platform_admin', 'platform_ops'] as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            if ($role !== null) {
                $role->permissions()->syncWithoutDetaching([$read->id, $manage->id]);
            }
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['platform.feature-flags.read', 'platform.feature-flags.manage'])->get();
        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
